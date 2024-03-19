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

    //模糊匹配
    public function scan($pattern, $count = 6000)
    {
        //SCAN命令是基于游标的，每次调用后，都会返回一个游标，用于下一次迭代。当游标返回0时，表示迭代结束。
        //第一次 Scan 时指定游标为 0，表示开启新的一轮迭代，然后 Scan 命令返回一个新的游标，作为第二次 Scan 时的游标值继续迭代，一直到 Scan 返回游标为0，表示本轮迭代结束。
        $keyArr = [];
        $redis = new \Redis();
        while (true) {
            // $iterator 下条数据的坐标
            $data = $redis->scan($iterator, $pattern, $count);
            $keyArr = array_merge($keyArr, $data ?: []);

            if ($iterator === 0) {
                //迭代结束，未找到匹配
                break;
            }
            if ($iterator === null) {
                //"游标为null了，重置为0，继续扫描"
                $iterator = "0";
            }
        }
        $keyArr = array_flip(array_flip($keyArr));
        return $keyArr;
    }

    //模糊匹配并删除
    function redisScan($pattern = null, $count = 6000, $is_del = 1)
    {
        $redis = new \Redis();
        //查出来key之后，若要批量删除，则可以使用redis管道 PIPELINE ，效果是 将多个命令合起来只执行一次，减少redis和客户端的交互时间；
        //其他批量操作也可以用PIPELINE，下面举个删除的例子：
        $keyArr = $redis->scan($pattern, $count); // 上面的scan方法
        if ($is_del) {
            $pipe = $redis->multi(2);   //使用管道
            foreach ($keyArr as $key) {
                $pipe->del($key);
            }
            $pipe->exec();
        } else {
            return $keyArr ?? [];
        }
    }
}