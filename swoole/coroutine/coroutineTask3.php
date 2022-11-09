<?php

/**
 * 不启动常驻监听进程server，直接一次性处理的任务
 *
 */

class coroutineTask2
{

    public function run(array $params)
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