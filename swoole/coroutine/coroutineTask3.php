<?php

/**
 * 对外提供api高并发访问接口
 * 使用常驻监听进程，pdo-mysql连接池，多协程处理业务逻辑
 *
 * apache bench压测数据，总数10000，并发数1000，总耗时2.996秒，每个请求299.605ms
 * [root@localhost ~]# ab -n 10000 -c 1000 http://192.168.92.208:9901/?limit=20
 * This is ApacheBench, Version 2.3 <$Revision: 1430300 $>
 * Copyright 1996 Adam Twiss, Zeus Technology Ltd, http://www.zeustech.net/
 * Licensed to The Apache Software Foundation, http://www.apache.org/
 *
 * Benchmarking 192.168.92.208 (be patient)
 * Completed 1000 requests
 * Completed 2000 requests
 * Completed 3000 requests
 * Completed 4000 requests
 * Completed 5000 requests
 * Completed 6000 requests
 * Completed 7000 requests
 * Completed 8000 requests
 * Completed 9000 requests
 * Completed 10000 requests
 * Finished 10000 requests
 *
 *
 * Server Software:        swoole-http-server
 * Server Hostname:        192.168.92.208
 * Server Port:            9901
 *
 * Document Path:          /?limit=20
 * Document Length:        2466 bytes
 *
 * Concurrency Level:      1000
 * Time taken for tests:   2.996 seconds
 * Complete requests:      10000
 * Failed requests:        0
 * Write errors:           0
 * Total transferred:      26160000 bytes
 * HTML transferred:       24660000 bytes
 * Requests per second:    3337.73 [#/sec] (mean)
 * Time per request:       299.605 [ms] (mean)
 * Time per request:       0.300 [ms] (mean, across all concurrent requests)
 * Transfer rate:          8526.86 [Kbytes/sec] received
 *
 * Connection Times (ms)
 * min  mean[+/-sd] median   max
 * Connect:        0   23 135.9      2    1007
 * Processing:    16   95  94.9     69    1622
 * Waiting:        1   75  84.3     59    1622
 * Total:         33  118 184.3     71    2626
 *
 * Percentage of the requests served within a certain time (ms)
 * 50%     71
 * 66%     76
 * 75%     80
 * 80%     83
 * 90%    272
 * 95%    281
 * 98%    439
 * 99%   1072
 * 100%   2626 (longest request)
 *
 */

class coroutineTask3
{
    /**
     * @var \Swoole\Database\PDOPool
     */
    protected $pdoPool;

    //PdoMysql连接池，协程支持并发访问请求接口
    public function test3(array $params = [])
    {
        $httpServer = new Swoole\Http\Server("0.0.0.0", 9901, SWOOLE_BASE);
        $httpServer->on('WorkerStart', function (Swoole\Server $server, int $workerId) {
            // Worker启动时创建MySQL和Redis连接池
            swoole_set_process_name("http-worker-" . $workerId);
            $this->pdoPool = $this->initPdoMysqlPool();
            echo 'WorkerStart pdo pool init.' . PHP_EOL;
        });
        $httpServer->on('WorkerStop', function (Swoole\Server $server, int $workerId) {
            $this->pdoPool->close();
            echo 'WorkerStop pdo pool close:' . $workerId . PHP_EOL;
        });
        $httpServer->on('request', function (Swoole\Http\Request $request, Swoole\Http\Response $response) {
            $limit = $request->get['limit'] ?? 200;
            $type = $request->get['type'] ?? 'Amazon';
            $uid = mt_rand();
            $return = [];
            $wg = new Swoole\Coroutine\WaitGroup();
            $wg->add(2);
            go(function () use ($wg, $limit, $type, &$return) {
                try {
                    $pdo = $this->pdoPool->get();
                    defer(function () use ($pdo) {
                        $this->pdoPool->put($pdo);
                    });
                    $sql = "select id,account_type,partner_id,account_real_name as account_s_name,created_time from yibai_amazon_account where account_type=:account_type limit {$limit}";
                    $PDOStatement = $pdo->prepare($sql);
                    //$PDOStatement->setFetchMode(PDO::FETCH_ASSOC);
                    $PDOStatement->execute([':account_type' => 1]);
                    $data = $PDOStatement->fetchAll(PDO::FETCH_ASSOC);
                    $return = ['status' => 1, 'data' => $data];
                } catch (Exception $e) {
                    $return = ['status' => 0, 'message' => $e->getMessage()];
                }
                $wg->done();
            });
            go(function () use ($wg, $uid) {
                try {
                    //@todo test demo
                    //$this->addUserLog();
                    //$this->sendUserMsg();
                    Swoole\Coroutine::sleep(1);
                } catch (Exception $e) {

                }
                $wg->done();
            });
            $wg->wait();
            return $response->end(json_encode($return));
        });
        $httpServer->start();
    }

    public function initPdoMysqlPool()
    {
        $dbServerKey = 'db_server_yibai_master';
        $key = 'db_account_manage';
        $db = [];
        include(APPPATH . 'config/database.php');
        $dbConfig = $db[$dbServerKey];
        $dbConfig['port'] = 3306;
        //Swoole 从 v4.4.13 版本开始提供了内置协程连接池，（使用swoole v4.5.11，还支持php7.1）
        $pdoConfig = (new Swoole\Database\PDOConfig())
            ->withHost($dbConfig['hostname'])
            ->withPort($dbConfig['port'])
            // ->withUnixSocket('/tmp/mysql.sock')
            ->withDbName($dbConfig['database'][$key])
            ->withCharset($dbConfig['char_set'])
            ->withUsername($dbConfig['username'])
            ->withPassword($dbConfig['password']);
        $pool = new Swoole\Database\PDOPool($pdoConfig, 128);
        return $pool;
    }

}