<?php

namespace module;

class Cache implements DiAwareInterface
{
    /**
     * 注入的di对象
     * @var Di
     */
    protected $_di;

    /**
     * 参数
     * @var null
     */
    protected $_options;

    /**
     * 具体连接对象
     * @var BackendInterface
     */
    protected $_connect;

    public function __construct($options = null)
    {
        $this->_options = $options;
    }

    public function setDi($di)
    {
        $this->_di = $di;
    }

    public function getDI()
    {
        // TODO: Implement getDI() method.
        return $this->_di;
    }

    protected function _connect()
    {
        $options = $this->_options;
        if (isset($options['type'])) {
            $service = $options['type'];
        } else {
            $service = 'redisCache';
        }
        return $this->_di->get($service);
    }

    public function get($key)
    {
        if (!is_object($this->_connect)) {
            $this->_connect = $this->_connect();
        }
        return $this->_connect->find($key);
    }

    public function save($key, $value, $lifetime)
    {
        if (!is_object($this->_connect)) {
            $this->_connect = $this->_connect();
        }
        return $this->_connect->save($key, $value, $lifetime);
    }

    public function delete($key)
    {
        if (!is_object($this->_connect)) {
            $this->_connect = $this->_connect();
        }
        return $this->_connect->delete($key);
    }
}