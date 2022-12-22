<?php

/**
 * redis-session Class
 * @package redis-session
 * @author  echosong <313690636@qq.com>
 * @link    https://github.com/echosong/
 */

class RedisSessionHandler extends \SessionHandler
{

    const MIN_WAIT_TIME = 1000;

    const MAX_WAIT_TIME = 128000;

    private $redis;

    private $lock_ttl;

    private $session_ttl;

    private $new_sessions = [];

    private $open_sessions = [];

    private $cookieName = null;

    /**
     * RedisSessionHandler constructor.
     * @param $config =[
     *  'host'=>'127.0.0.1',
     *  'port'=> 6379,
     *  'timeout'=>2,
     *  'auth'=>'****',
     *  'database'=>2,
     *   'prefix' => 'redis_session:',
     * ]
     */
    public function __construct($config)
    {
        if (false === extension_loaded('redis')) {
            throw new \RuntimeException("the 'redis' extension is needed in order to use this session handler");
        }
        $this->lock_ttl = (int)ini_get('max_execution_time');
        $this->session_ttl = (int)ini_get('session.gc_maxlifetime');

        $this->redis = new \Redis();
        if (false === $this->redis->connect($config['host'], $config['port'], $config['timeout'])) {
            throw new \RuntimeException("the 'redis' cant't  to connection");
        }
        if (!empty($config['auth'])) {
            $this->redis->auth($config['auth']);
        }
        if (!empty($config['database'])) {
            $this->redis->select($config['database']);
        }
        if (empty($config['prefix'])) {
            $config['prefix'] = 'redis_session:';
        }
        $this->redis->setOption(\Redis::OPT_PREFIX, $config['prefix']);
    }

    /**
     * {@inheritdoc}
     */
    public function open($save_path, $name)
    {
        $this->cookieName = $name;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function create_sid()
    {
        $id = parent::create_sid();

        $this->new_sessions[$id] = true;

        return $id;
    }

    /**
     * {@inheritdoc}
     */
    public function read($session_id)
    {
        if ($this->mustRegenerate($session_id)) {
            session_id($session_id = $this->create_sid());
            $params = session_get_cookie_params();
            setcookie($this->cookieName, $session_id, time() + $params['lifetime'], $params['path'], $params['domain'],
                $params['secure'], $params['httponly']);
        }

        $this->acquireLockOn($session_id);

        if ($this->isNew($session_id)) {
            return '';
        }
        return $this->redis->get($session_id);

    }

    /**
     * {@inheritdoc}
     */
    public function write($session_id, $session_data)
    {
        return true === $this->redis->setex($session_id, $this->session_ttl, $session_data);
    }

    /**
     * {@inheritdoc}
     */
    public function destroy($session_id)
    {
        $this->redis->del($session_id);
        $this->redis->del("{$session_id}_lock");

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->releaseLocks();

        $this->redis->close();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function gc($maxlifetime)
    {
        return true;
    }

    /**
     * @param string $session_id
     */
    private function acquireLockOn($session_id)
    {
        $options = ['nx'];
        if (0 < $this->lock_ttl) {
            $options = ['nx', 'ex' => $this->lock_ttl];
        }

        $wait = self::MIN_WAIT_TIME;
        while (false === $this->redis->set("{$session_id}_lock", '', $options)) {
            usleep($wait);

            if (self::MAX_WAIT_TIME > $wait) {
                $wait *= 2;
            }
        }
        $this->open_sessions[] = $session_id;
    }

    private function releaseLocks()
    {
        foreach ($this->open_sessions as $session_id) {
            $this->redis->del("{$session_id}_lock");
        }

        $this->open_sessions = [];
    }

    /**
     * A session ID must be regenerated when it came from the HTTP
     * request and can not be found in Redis
     * @param string $session_id
     *
     * @return bool
     */
    private function mustRegenerate($session_id)
    {
        return false === $this->isNew($session_id)
            && false === $this->redis->exists($session_id);
    }

    /**
     * @param string $session_id
     *
     * @return bool
     */
    private function isNew($session_id)
    {
        return isset($this->new_sessions[$session_id]);
    }
}
