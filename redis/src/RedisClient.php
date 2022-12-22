<?php

class RedisClient
{

    protected $host;
    protected $port;
    protected $password;
    protected $timeout;
    protected $base;
    /**
     * @var \Redis
     */
    protected $redis;

    public function __construct($host = '127.0.0.1', $port = 6379, $password = '', $timeout = 0, $base = null)
    {
        $this->host = $host;
        $this->port = $port;
        $this->password = $password;
        $this->timeout = $timeout;
        $this->base = $base;
    }

    public function __destruct()
    {
        if (null === $this->redis) {
            return;
        }
        $this->redis->close();
    }

    public function connect()
    {
        if (null !== $this->redis) {
            return;
        }
        $this->redis = new \Redis();
        $this->redis->connect($this->host, $this->port, $this->timeout);
        if (!empty($this->password)) {
            $this->redis->auth($this->password);
        }
        if (null !== $this->base) {
            try {
                $this->redis->select($this->base);
            } catch (\RedisException $e) {
                throw new \RedisException(sprintf('%s (%s:%d)', $e->getMessage(), $this->host, $this->port), $e->getCode(), $e);
            }
        }
    }

    public function open()
    {
        $this->connect();
    }


    public function close()
    {
        if (null === $this->redis) {
            return;
        }
        $this->redis->close();
        $this->redis = null;
    }

    public function getRedis()
    {
        return $this->redis;
    }

    //魔术方法调用redis方法
    public function __call($name, array $arguments)
    {
        if (null === $this->redis) {
            $this->connect();
        }
        try {
            $return = call_user_func_array(array($this->redis, $name), $arguments);
            return $return;
        } catch (RedisException $e) {
            throw $e;
        }
    }

    public function hscan($key, &$iterator, $pattern = null, $count = 0)
    {
        if (null === $this->redis) {
            $this->connect();
        }
        return $this->redis->hscan($key, $iterator, $pattern, $count);
    }

}
