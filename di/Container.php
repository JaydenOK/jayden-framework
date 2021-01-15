<?php

namespace module;

use module\DiAwareInterface;

class Container
{
    /**
     * @var array
     */
    protected $service = [];
    protected $resolveService = [];

    public function __construct()
    {
        $dependencies = MODULE_DIR . DS . 'config' . DS . 'dependencies.php';
        if (file_exists($dependencies)) {
            $config = include($dependencies);
            if (!empty($config)) {
                $this->service = array_merge($this->service, $config);
            }
        }
    }

    /**
     * 将配置保存到$service数组
     * @param $name
     * @param $definition
     */
    public function set($name, $definition)
    {
        $this->service[$name] = $definition;
    }

    public function get($name)
    {
        //已实例化的对象，直接返回
        if (isset($this->resolveService[$name])) {
            return $this->resolveService[$name];
        }
        if (!isset($this->service[$name])) {
            exit("Service '{$name}' wasn't found in the dependency injection container");
        }
        $definition = $this->service[$name];
        if (is_callable($definition)) {
            $this->resolveService[$name] = $definition();
        } else if (is_object($definition)){
            $this->resolveService[$name] = $definition;
        } else if(is_string($definition) && class_exists($definition)){
            $this->resolveService[$name] = new $definition();
        }
        // 如果实现了DiAwareInterface这个接口，自动注入
        if ($this->resolveService[$name] instanceof DiAwareInterface) {
            $this->resolveService[$name]->setDI($this);
        }
        return $this->resolveService[$name];
    }
}