<?php

/**
 * 对外提供api并发访问接口
 * 使用常驻监听进程，pdo-mysql连接池，协程获取连接并处理业务数据
 *
 */

class coroutineTask3
{

    //PdoMysql连接池，协程支持并发访问请求接口
    /**
     * @var \Swoole\Database\PDOPool
     */
    protected $pdoPool;

    public function run(array $params = [])
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
            $pdo = $this->pdoPool->get();
            defer(function () use ($pdo) {
                $this->pdoPool->put($pdo);
            });
            $limit = $request->get['limit'] ?? 500;
            $type = $request->get['type'] ?? 'Amazon';
            $uid = mt_rand();
            $return = [];
            $wg = new Swoole\Coroutine\WaitGroup();
            $wg->add(2);
            go(function () use ($wg, $pdo, $limit, $type, &$return) {
                try {
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
                    Swoole\Coroutine::sleep(2);
                } catch (Exception $e) {

                }
                $wg->done();
            });
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
        //当前使用swoole v4.5.11（Swoole 从 v4.4.13 版本开始提供了内置协程连接池，swoole 4.6+不再支持php7.1）
        $pdoConfig = (new Swoole\Database\PDOConfig())
            ->withHost($dbConfig['hostname'])
            ->withPort($dbConfig['port'])
            // ->withUnixSocket('/tmp/mysql.sock')
            ->withDbName($dbConfig['database'][$key])
            ->withCharset($dbConfig['char_set'])
            ->withUsername($dbConfig['username'])
            ->withPassword($dbConfig['password']);
        $pool = new Swoole\Database\PDOPool($pdoConfig, 64);
        return $pool;
    }

}