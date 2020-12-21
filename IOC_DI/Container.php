<?php

namespace module;

use Closure;
use ReflectionClass;

class Container
{

    public $bindings = [];

    /**
     * 支持字符串或闭包绑定
     * @param $key string
     * @param $value string | closure
     */
    public function bind($key, $value)
    {
        if (!$value instanceof Closure) {
            $this->bindings[$key] = $this->getClosure($value);
        } else {
            $this->bindings[$key] = $value;
        }
    }

    /**
     * 匿名函数，不会立即实例化对象，使用时才实例化
     * @param $value
     * @return Closure
     */
    public function getClosure($value)
    {
        return function () use ($value) {
            return $this->build($value);
        };
    }

    public function make($key)
    {
        if (isset($this->bindings[$key])) {
            return $this->build($this->bindings[$key]);
        }
        return $this->build($key);
    }

    public function build($value)
    {
        if ($value instanceof Closure) {
            return $value();
        }
        // 实例化反射类
        try {
            $reflection = new ReflectionClass($value);
        } catch (\ReflectionException $e) {
            print_r($e->getMessage());
            exit;
        }
        // isInstantiable() 方法判断类是否可以实例化
        $isInstantiable = $reflection->isInstantiable();
        if ($isInstantiable) {
            // getConstructor() 方法获取类的构造函数，为NULL没有构造函数
            $constructor = $reflection->getConstructor();
            if (is_null($constructor)) {
                // 没有构造函数直接实例化对象返回
                return new $value;
            } else {
                // 有构造函数
                $params = $constructor->getParameters();
                if (empty($params)) {
                    // 构造函数没有参数，直接实例化对象返回
                    return new $value;
                } else {
                    $dependencies = [];
                    // 构造函数有参数
                    foreach ($params as $param) {
                        $dependency = $param->getClass();
                        if (is_null($dependency)) {
                            // 构造函数参数不为class，返回NULL
                            $dependencies[] = NULL;
                        } else {
                            // 类存在创建类实例:$param->getClass()->name => 'module\interfaces\IMysql';
                            // 命名空间有问题，没有命名空间，绑定的实例化对象名称，刚好是接口的名称？？
                            $dependencies[] = $this->make($param->getClass()->name);
                        }
                    }
                    return $reflection->newInstanceArgs($dependencies);
                }
            }
        }
        return null;
    }

}