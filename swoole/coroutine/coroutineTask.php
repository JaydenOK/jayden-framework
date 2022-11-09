<?php
/**
 * Coroutine协程并发实例
 *
 * 常驻监听进程启动，Http Server + 协程 + channel 实现并发处理，可控制并发数量，分批次执行任务，适用于内部系统要处理大量耗时的任务
 *
 * 并发请求亚马逊。虾皮电商平台接口，测试结果如下
 *
 * [root@ac_web yibai_ac_system]# php /mnt/yibai_ac_system/appdal/index.php swoole coroutineTask run
 *
 * [root@ac_web yibai_ac_system]# curl "127.0.0.1:9900/?task_type=Amazon&concurrency=5&total=200"
 * {"taskCount":200,"concurrency":5,"useTime":"56s"}
 *
 * [root@ac_web yibai_ac_system]# curl "127.0.0.1:9900/?task_type=Amazon&concurrency=10&total=200"
 * {"taskCount":200,"concurrency":10,"useTime":"28s"}
 *
 * [root@ac_web yibai_ac_system]# curl "127.0.0.1:9900/?task_type=Amazon&concurrency=20&total=200"
 * {"taskCount":200,"concurrency":20,"useTime":"10s"}
 *
 * [root@ac_web yibai_ac_system]# curl "127.0.0.1:9900/?task_type=Amazon&concurrency=50&total=200"
 * {"taskCount":200,"concurrency":50,"useTime":"6s"}
 *
 * [root@ac_web yibai_ac_system]# curl "127.0.0.1:9900/?task_type=Amazon&concurrency=200&total=500"
 * {"taskCount":500,"concurrency":200,"useTime":"3s"}
 */

use end\modules\common\models\AmazonAccountModel;
use end\modules\common\models\AmazonSiteModel;

class coroutineTask
{

    public function run($params)
    {
        $taskType = isset($params['task_type']) ? (string)$params['task_type'] : 'Amazon';
        $port = isset($params['port']) ? (string)$params['port'] : 9900;
        if (empty($taskType) || empty($port)) {
            return 'params not support';
        }
        $this->renameProcessName("concurrency-task-{$taskType}");
        $httpServer = new Swoole\Http\Server("0.0.0.0", $port, SWOOLE_BASE);
        //开启内置协程，默认开启
        //当 enable_coroutine 设置为 true 时，底层自动在 onRequest 回调中创建协程，开发者无需自行使用 go 函数创建协程
        //当 enable_coroutine 设置为 false 时，底层不会自动创建协程，开发者如果要使用协程，必须使用 go 自行创建协程
        $httpServer->set(['enable_coroutine' => true]);
        $httpServer->on('request', function (Swoole\Http\Request $request, Swoole\Http\Response $response) {
            try {
                $concurrency = isset($request->get['concurrency']) ? (int)$request->get['concurrency'] : 5;  //并发数
                $total = isset($request->get['total']) ? (int)$request->get['total'] : 100;  //需总处理记录数
                $taskType = isset($request->get['task_type']) ? (string)$request->get['task_type'] : '';  //任务类型
                if ($concurrency <= 0 || empty($taskType)) {
                    return $response->end('error params');
                }
                //数据库配置信息
                $dbServerKey = 'db_server_yibai_master';
                $key = 'db_account_manage';
                /**
                 * @var CI_DB_mysqli_driver $db
                 */
                $taskList = $this->getTaskList($taskType, $total);
                if (empty($taskList)) {
                    return $response->end('not task wait');
                }
                $taskCount = count($taskList);
                $startTime = time();
                echo "task count:{$taskCount}" . PHP_EOL;
                $taskChan = new chan($taskCount);
                //初始化并发数量
                $producerChan = new chan($concurrency);
                $dataChan = new chan($total);
                for ($size = 1; $size <= $concurrency; $size++) {
                    $producerChan->push(1);
                }
                foreach ($taskList as $task) {
                    //增加当前任务类型标识
                    $task = array_merge($task, ['task_type' => $taskType]);
                    $taskChan->push($task);
                }
                //创建生产者协程，投递任务
                //创建协程处理请求
                go(function () use ($taskChan, $producerChan, $dataChan) {
                    while (true) {
                        $chanStatsArr = $taskChan->stats(); //queue_num 通道中的元素数量
                        if (!isset($chanStatsArr['queue_num']) || $chanStatsArr['queue_num'] == 0) {
                            //queue_num 通道中的元素数量
                            echo 'chanStats:' . json_encode($chanStatsArr, 256) . PHP_EOL;
                            break;
                        }
                        //阻塞获取
                        $producerChan->pop();
                        $task = $taskChan->pop();
                        go(function () use ($producerChan, $dataChan, $task) {
                            echo 'producer:' . $task['id'] . PHP_EOL;
                            $responseBody = $this->handleProducerByTask($task['task_type'], $task);
                            echo 'deliver:' . $task['id'] . PHP_EOL;
                            $pushStatus = $dataChan->push(['task_type' => $task['task_type'], 'id' => $task['id'], 'responseBody' => $responseBody]);
                            if ($pushStatus !== true) {
                                echo 'push errCode:' . $dataChan->errCode . PHP_EOL;
                            }
                            //处理完，恢复producerChan协程
                            $producerChan->push(1);
                            echo "producer:{$task['id']} done" . PHP_EOL;
                        });
                    }
                });
                //消费数据
                $db = createDbConnection($dbServerKey, $key);
                for ($i = 1; $i <= $taskCount; $i++) {
                    //阻塞，等待投递结果, 通道被关闭时，执行失败返回 false,
                    $receiveData = $dataChan->pop();
                    if ($receiveData === false) {
                        echo 'pop errCode:' . $dataChan->errCode . PHP_EOL;
                        //退出
                        break;
                    }
                    echo 'receive:' . $receiveData['id'] . PHP_EOL;
                    $this->handleConsumerByResponseData($receiveData['task_type'], $receiveData['id'], $receiveData['responseBody'], $db);
                }
                $db->close();
                //返回响应
                $endTime = time();
                $return = ['taskCount' => $taskCount, 'concurrency' => $concurrency, 'useTime' => ($endTime - $startTime) . 's'];
                return $response->end(json_encode($return));
            } catch (Swoole\ExitException $e) {
                return $response->end(json_encode($e->getMessage()));
            }
        });
        $httpServer->start();
    }

    //获取不同平台任务列表
    public function getTaskList(string $taskType, int $total)
    {
        $lists = [];
        $dbServerKey = 'db_server_yibai_master';
        $key = 'db_account_manage';
        $db = createDbConnection($dbServerKey, $key);
        //查询出要处理的记录
        if ($taskType == 'Amazon') {
            $lists = $db->where('id<', 1000)->limit($total)->get('yibai_amazon_account')->result_array();
        } else if ($taskType == 'Shopee') {
            $lists = $db->where('id<', 1000)->limit($total)->get('yibai_shopee_account')->result_array();
        }
        $db->close();
        return $lists;
    }

    //任务处理程序
    public function handleProducerByTask($taskType, $task)
    {
        $responseBody = null;
        if ($taskType == 'Amazon') {
            $id = $task['id'];
            $appId = $task['app_id'];
            $sellingPartnerId = $task['selling_partner_id'];
            $host = 'api.amazon.com';
            $path = '/auth/o2/token';
            $data = [];
            $data['grant_type'] = 'refresh_token';
            $data['client_id'] = '111';
            $data['client_secret'] = '222';
            $data['refresh_token'] = '333';
            $cli = new Swoole\Coroutine\Http\Client($host, 443, true);
            $cli->set(['timeout' => 10]);
            $cli->setHeaders([
                'Host' => $host,
                'grant_type' => 'refresh_token',
                'client_id' => 'refresh_token',
                "User-Agent" => 'Chrome/49.0.2587.3',
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
            ]);
            $cli->post($path, http_build_query($data));
            $responseBody = $cli->body;
        } else if ($taskType == 'Shopee') {
            $host = 'partner.shopeemobile.com';
            $timestamp = time();
            $path = '/api/v2/auth/access_token/get';
            $sign = '111';
            $data = [];
            $data['partner_id'] = 111;
            $data['refresh_token'] = '222';
            $data['merchant_id'] = 333;
            $path .= '?timestamp=' . $timestamp . '&sign=' . $sign . '&partner_id=' . $data['partner_id'];
            $cli = new Swoole\Coroutine\Http\Client($host, 443, true);
            $cli->set(['timeout' => 10]);
            $cli->setHeaders([
                'Host' => $host,
                'Content-Type' => 'application/json;charset=UTF-8',
            ]);
            $data = [];
            $cli->post($path, json_encode($data));
            $responseBody = $cli->body;
        }
        return $responseBody;
    }

    public function handleConsumerByResponseData($taskType, $id, $responseBody, $db)
    {
        /**
         * 协程内，创建mysql连接新的实例
         * 由 &DB() 函数创建对应driver对象
         * @var CI_DB_mysqli_driver $db
         */
        //处理业务逻辑
        if ($taskType == 'Amazon') {
            $db->where(['id' => $id])->set(['refresh_msg' => json_encode($responseBody, 256), 'refresh_time' => date('Y-m-d H:i:s')])->update('yibai_amazon_account');
        } else if ($taskType == 'Shopee') {
            $db->where(['id' => $id])->set(['refresh_msg' => json_encode($responseBody, 256), 'refresh_time' => date('Y-m-d H:i:s')])->update('yibai_shopee_account');
        }
        echo "consumer:{$id} done" . PHP_EOL;
    }

    //当前进程重命名
    protected function renameProcessName($processName)
    {
        if (function_exists('cli_set_process_title')) {
            return cli_set_process_title($processName);
        } else {
            return swoole_set_process_name($processName);
        }
    }

}