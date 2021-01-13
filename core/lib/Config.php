<?php


namespace app\core\lib;


use RuntimeException;

class Config
{

    protected $config = [];

    public function __construct($config = [], $prefix = '')
    {
        $this->config = $prefix ? [$prefix => $config] : $config;
    }

    public function set($key, $value)
    {
        $config = &$this->config;

        foreach (explode('.', $key) as $k) {
            $config = &$config[$k];
        }

        $config = $value;

        return true;
    }

    public function get($key = '', $default = null)
    {
        $config = $this->config;

        foreach (explode('.', $key) as $k) {
            if (!isset($config[$k])) {
                return $default;
            }
            $config = $config[$k];
        }

        return $config;
    }

    public function has($key)
    {
        $config = $this->config;

        foreach (explode('.', $key) as $k) {
            if (!isset($config[$k])) {
                return false;
            }
            $config = $config[$k];
        }

        return true;
    }

    public function append($key, $value)
    {
        $config = &$this->config;

        foreach (explode('.', $key) as $k) {
            $config = &$config[$k];
        }

        if (!is_array($config)) {
            throw new RuntimeException("Config item '{$key}' is not an array");
        }

        $config = array_merge($config, (array)$value);

        return true;
    }

    public function prepend($key, $value)
    {
        $config = &$this->config;

        foreach (explode('.', $key) as $k) {
            $config = &$config[$k];
        }

        if (!is_array($config)) {
            throw new RuntimeException("Config item '{$key}' is not an array");
        }

        $config = array_merge((array)$value, $config);

        return true;
    }

    //PHP7可用 unset函数名
    public function unsetConfig($key)
    {
        if (!$this->has($key)) {
            return false;
        }

        return $this->set($key, null);
    }

    public function toArray()
    {
        return $this->config;
    }
}