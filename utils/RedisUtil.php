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

    /**
     * 各个web服务器来获取分布式锁；生成随机值，当前服务器锁标识，释放锁，查看是否是自己的锁；锁的超时时间应该 >> 超时时间
     * 增强：为获取的锁增加一个守护线程，为将要过期但未释放的锁增加有效时间
     * @param $key
     * @param int $expire
     * @return string 加锁成功，返回当前线程唯一锁，返回空加锁失败
     */
    public function getLock($key, $expire = 60)
    {
        $lockId = md5(uniqid());    //加当前线程id
        $isSuccess = $this->redis->set($key, $lockId, ['NX', 'EX' => $expire]);
        if ($isSuccess) {
            return $lockId;
        }
        return false;
    }

    //释放分布式锁
    public function releaseLock($key, $lockId)
    {
        //此处二条语句(get delete)改用lua执行，因为Redis在执行Lua脚本时，可以以原子性的方式执行，保证了锁释放操作的原子性
        //通过使用SET命令和 Lua 脚本在 Redis 单节点上完成了分布式锁的加锁和解锁。
        $lastLockId = $this->redis->get($key);
        if ($lastLockId === $lockId) {
            //是自己加的锁，释放锁
            $this->redis->delete($key);
            return true;
        } else {
            //不是自己加的锁，说明当前请求已超时，锁已被其它线程修改
            return false;
        }
    }

}