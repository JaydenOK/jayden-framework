<?php

//Coroutine协程并发实例

/**
 * 并发请求美客多接口，测试结果如下
 *
 * [root@ac_web yibai_ac_system]# php /mnt/yibai_ac_system/appdal/index.php swoole coroutineTask httpServerTask
 *
 * [root@ac_web yibai_ac_system]# curl "127.0.0.1:9503/?platform_code=Amazon&concurrency=5&total=200"
 * {"taskCount":200,"concurrency":5,"useTime":"56s"}
 * [root@ac_web yibai_ac_system]#
 * [root@ac_web yibai_ac_system]# curl "127.0.0.1:9503/?platform_code=Amazon&concurrency=10&total=200"
 * {"taskCount":200,"concurrency":10,"useTime":"28s"}
 * [root@ac_web yibai_ac_system]#
 * [root@ac_web yibai_ac_system]# curl "127.0.0.1:9503/?platform_code=Amazon&concurrency=20&total=200"
 * {"taskCount":200,"concurrency":20,"useTime":"10s"}
 * [root@ac_web yibai_ac_system]#
 * [root@ac_web yibai_ac_system]# curl "127.0.0.1:9503/?platform_code=Amazon&concurrency=50&total=200"
 * {"taskCount":200,"concurrency":50,"useTime":"6s"}
 * [root@ac_web yibai_ac_system]#
 */

use end\modules\common\models\AmazonAccountModel;
use end\modules\common\models\AmazonSiteModel;

class coroutineTask
{

    //Http Server + 协程 + channel 实现常驻进程并发，可控制并发数量，分批次执行，适用于要处理大量耗时的任务
    public function httpServerTask()
    {
        $httpServer = new Swoole\Http\Server("0.0.0.0", 9503, SWOOLE_BASE);
        $httpServer->on('request', function (Swoole\Http\Request $request, Swoole\Http\Response $response) {
            $concurrency = isset($request->get['concurrency']) ? (int)$request->get['concurrency'] : 5;  //并发数
            $total = isset($request->get['total']) ? (int)$request->get['total'] : 100;  //需总处理记录数
            $platformCode = isset($request->get['platform_code']) ? (string)$request->get['platform_code'] : '';
            if ($concurrency <= 0 || empty($platformCode)) {
                return $response->end('error params');
            }
            //数据库配置信息
            $dbServerKey = 'db_server_yibai_master';
            $key = 'db_account_manage';
            /**
             * @var CI_DB_mysqli_driver $db
             */
            $db = createDbConnection($dbServerKey, $key);
            //查询出要处理的记录
            $lists = $db->where('id<', 1000)->limit($total)->get('yibai_amazon_account')->result_array();
            $db->close();
            if (empty($lists)) {
                return $response->end('not task wait');
            }
            $taskCount = count($lists);
            $startTime = time();
            echo "task count:{$taskCount}" . PHP_EOL;
            $taskChan = new chan($taskCount);
            //初始化并发数量
            $producerChan = new chan($concurrency);
            $dataChan = new chan($total);
            for ($size = 1; $size <= $concurrency; $size++) {
                $producerChan->push($size);
            }
            foreach ($lists as $account) {
                $taskChan->push($account);
            }
            //创建生产者协程，投递任务
            //创建协程处理请求
            go(function () use ($taskChan, $producerChan, $dataChan) {
                while (true) {
                    $chanStatsArr = $taskChan->stats(); //queue_num 通道中的元素数量
                    if (!isset($chanStatsArr['queue_num']) || $chanStatsArr['queue_num'] == 0) {
                        //queue_num 通道中的元素数量
                        echo 'chanStats:' . print_r($chanStatsArr, true) . PHP_EOL;
                        break;
                    }
                    //阻塞获取
                    $producerChan->pop();
                    $account = $taskChan->pop();
                    go(function () use ($producerChan, $dataChan, $account) {
                        echo 'producer:' . $account['id'] . PHP_EOL;
                        $id = $account['id'];
                        $appId = $account['app_id'];
                        $sellingPartnerId = $account['selling_partner_id'];
                        //https://api.mercadolibre.com/oauth/token
                        $host = 'api.mercadolibre.com';
                        $cli = new Swoole\Coroutine\Http\Client($host, 443, true);
                        $cli->set(['timeout' => 10]);
                        $cli->setHeaders([
                            'Host' => $host,
                            "User-Agent" => 'Chrome/49.0.2587.3',
                            'Accept' => 'text/html,application/json',
                            'Accept-Encoding' => 'gzip',
                        ]);
                        $result = $cli->post('/oauth/token', []);
                        echo 'deliver:' . $account['id'] . PHP_EOL;
                        $result = $dataChan->push(['id' => $id, 'data' => $cli->body]);
                        if ($result !== true) {
                            echo 'push errCode:' . $dataChan->errCode . PHP_EOL;
                        }
                        $producerChan->push(1);
                        echo "producer:{$account['id']} done" . PHP_EOL;
                    });
                }
            });
            //消费数据
            $db = createDbConnection($dbServerKey, $key);
            for ($i = 1; $i <= $taskCount; $i++) {
                //阻塞，等待投递结果, 通道被关闭时，执行失败返回 false,
                $result = $dataChan->pop();
                echo "create consumer:{$result['id']} done" . PHP_EOL;
                if ($result === false) {
                    echo 'pop errCode:' . $dataChan->errCode . PHP_EOL;
                    //退出
                    break;
                }
                echo 'receive:' . $result['id'] . PHP_EOL;
                $id = $result['id'];
                $data = $result['data'];
                /**
                 * 协程内，创建mysql连接新的实例
                 * 由 &DB() 函数创建对应driver对象
                 * @var CI_DB_mysqli_driver $db
                 */
                //处理业务逻辑
                $db->where(['id' => $id])->set(['refresh_msg' => json_encode($data, 256), 'refresh_time' => date('Y-m-d H:i:s')])->update('yibai_amazon_account');
                echo "consumer:{$result['id']} done" . PHP_EOL;
            }
            $db->close();
            //返回响应
            $endTime = time();
            $return = ['taskCount' => $taskCount, 'concurrency' => $concurrency, 'useTime' => ($endTime - $startTime) . 's'];
            return $response->end(json_encode($return));
        });
        $httpServer->start();
    }


    public function demo2(array $params)
    {
        //一键协程化
        Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        //所有的协程必须在协程容器里面创建，Swoole 程序启动的时候大部分情况会自动创建协程容器，其他直接裸写协程的方式启动程序，需要先创建一个协程容器 (Coroutine\run() 函数
        Swoole\Coroutine\Run(function () {
            $total = isset($params['limit']) && !empty($params['total']) ? (int)$params['total'] : 100;
            $id = isset($params['id']) && !empty($params['id']) ? $params['id'] : '';
            $dbServerKey = 'db_server_yibai_master';
            $key = 'db_account_manage';
            $db = createDbConnection($dbServerKey, $key);
            //查询出要处理的记录
            $lists = $db->where('id<', 1000)->limit($total)->get('yibai_amazon_account')->result_array();
            if (empty($lists)) {
                return 'not task wait';
            }
            echo date('[Y-m-d H:i:s]') . 'total:' . count($lists) . print_r(array_column($lists, 'id'), true) . PHP_EOL;
            $batchRunNum = 10;
            $batchAccountList = array_chunk($lists, $batchRunNum);
            foreach ($batchAccountList as $key => $accountList) {
                $result = [];
                //分批次执行
                $wg = new Swoole\Coroutine\WaitGroup();
                foreach ($accountList as $account) {
                    echo date('[Y-m-d H:i:s]') . "id: {$account['id']},running" . PHP_EOL;
                    // 增加计数
                    $wg->add();
                    //父子协程优先级
                    //优先执行子协程 (即 go() 里面的逻辑)，直到发生协程 yield(co::sleep 处)，然后协程调度到外层协程
                    go(function () use ($wg, &$result, $account) {
                        //启动一个协程客户端client
                        $cli = new Swoole\Coroutine\Http\Client('api.amazon.com', 443, true);
                        $cli->setHeaders([
                            'Host' => 'api.amazon.com',
                            'User-Agent' => 'Chrome/49.0.2587.3',
                            'Accept' => 'text/html,application/xhtml+xml,application/xml',
                            'Accept-Encoding' => 'gzip',
                        ]);
                        $cli->set(['timeout' => 1]);
                        $cli->setMethod('POST');
                        $cli->get('/auth/o2/token');
                        $result[] = $cli->body;
                        $cli->close();
                        //完成减少计数
                        $wg->done();
                    });
                }
                // 主协程等待，挂起当前协程，等待所有任务完成后恢复当前协程的执行
                $wg->wait();
                foreach ($result as $item) {
                    $id = $item['id'];
                    echo date('[Y-m-d H:i:s]') . "id: {$id} done,result:" . json_encode($item, 256) . PHP_EOL;
                }
            }
        });
        return 'finish';
    }

}