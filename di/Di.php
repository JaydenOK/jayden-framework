<?php

namespace module;

class Di
{
    protected $service;

    public function set($name, $definition)
    {
        $this->service[$name] = $definition;
    }

    public function get($name)
    {
        if (isset($this->service[$name])) {
            $definition = $this->service[$name];
        } else {
            exit("Service '{$name}' wasn't found in the dependency injection container");
        }
        if (is_object($definition)) {
            $instance = call_user_func($definition);
        }
        // 如果实现了DiAwareInterface这个接口，自动注入
        if ($instance instanceof DiAwareInterface) {
            $instance->setDI($this);
        }
        return $instance;
    }
}