<?php
/**
 * Redis工具类
 *
 * $redisUtil = new RedisUtil();
 * $redis = $redisUtil->getRedis();
 * $redis->set('a', time(), 60);
 */

namespace app\utils;

class RedisUtil
{
    protected $host;
    protected $port;
    protected $password;
    protected $timeout;
    protected $baseDatabase;
    /**
     * @var \Redis
     */
    protected $redis;

    public function __construct($host = '127.0.0.1', $port = 6379, $password = '', $timeout = 0, $baseDatabase = null)
    {
        $this->host = $host;
        $this->port = $port;
        $this->password = $password;
        $this->timeout = $timeout;
        $this->baseDatabase = $baseDatabase;
        $this->connect();
    }

    protected function connect()
    {
        if (null !== $this->redis) {
            return;
        }
        $this->redis = new \Redis();
        $this->redis->connect($this->host, $this->port, $this->timeout);
        if (!empty($this->password)) {
            $this->redis->auth($this->password);
        }
        if (null !== $this->baseDatabase) {
            $this->redis->select($this->baseDatabase);
        }
    }

    public function close()
    {
        if (null === $this->redis) {
            return;
        }
        $this->redis->close();
        $this->redis = null;
    }

    protected function __destruct()
    {
        if (null === $this->redis) {
            return;
        }
        $this->redis->close();
    }

    /**
     * @return \Redis
     */
    public function getRedis()
    {
        return $this->redis;
    }

}