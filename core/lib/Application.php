<?php

namespace app\core\lib;

use app\core\lib\request\Request;
use Exception;
use ReflectionClass;
use ReflectionException;

class Application
{

    /**
     * 请求路由参数,如 test/validate/show，test/validate（则执行test/validate/run方法）
     * @var string
     */
    protected $route;
    /**
     * 类所在路径
     * @var
     */
    private $classPath;
    /**
     * 请求参数
     * @var
     */
    private $_queryParams;
    /**
     * 请求方法
     * @var string
     */
    private $_method;
    /**
     * 模块（即目录名）
     * @var string
     */
    private $moduleName;
    /**
     * 控制器，有且只有第一个大写字母
     * @var
     */
    private $className;
    /**
     * 方法，默认执行run方法
     * @var bool
     */
    private $methodName = 'run';
    /**
     * @var string
     */
    private $_body;


    public function __construct()
    {
        //将函数注册到SPL __autoload函数队列中。如果该队列中的函数尚未激活，则激活它们
        //autoload 方法不能出现异常信息，否则不会执行其它自动加载，如vendor的composer加载
        spl_autoload_register([$this, 'autoload'], true, true);
        $this->_body = Request::getBody();
        $this->initRoute();
    }

    private function initRoute()
    {
        if (!isset($this->_body['route']) || empty($this->_body['route']) || !is_string($this->_body['route'])) {
            // welcome
            echo 'Hi, john-utils!';
            exit(0);
        }
        $this->route = $this->_body['route'];
        unset($this->_body['route']);
    }

    public function run()
    {
        $this->checkRoute($this->route);
        try {
            if (!class_exists($this->className)) {
                throw new Exception("控制器不存在:[{$this->className}]");
            }
            if (!method_exists($this->className, $this->methodName)) {
                throw new Exception("{$this->methodName}方法不存在:[{$this->className}->{$this->methodName}]");
            }
            $controller = new $this->className();
            call_user_func_array([$controller, $this->methodName], ['body' => $this->_body]);
//            $reflectionClass = new ReflectionClass($this->className);
//            $method = $reflectionClass->getMethod($this->methodName);
//            $method->setAccessible(true);
//            $method->invokeArgs(new $this->className(), ['body' => $this->_body]);
        } catch (ReflectionException $e) {
            print_r($e);
        }
    }

    /**
     * @param $route
     * @throws Exception
     */
    private function checkRoute($route)
    {
        $routeArr = explode('/', $route);
        $this->moduleName = isset($routeArr[0]) ? strtolower($routeArr[0]) : '';
        $this->className = isset($routeArr[1]) ? ucfirst(strtolower($routeArr[1])) : '';
        $this->methodName = isset($routeArr[2]) ? strtolower($routeArr[2]) : $this->methodName;
        if (empty($this->moduleName) || empty($this->className)) {
            throw new Exception("路由错误:[{$this->route}]");
        }
        //加上命名空间
        $this->className = APP_NAME . '\\' . $this->moduleName . '\\' . $this->className;
    }

    public function autoload($class)
    {
        if ($file = $this->findFile($class)) {
            include $file;
            return true;
        }
    }

    private function findFile($class)
    {
        $file = str_replace([APP_NAME, '\\'], [APP_ROOT, DS], $class) . '.php';
        if (file_exists($file)) {
            return $file;
        }
        return null;
    }
}