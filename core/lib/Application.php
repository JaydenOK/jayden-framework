<?php

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
        require 'request/Request.php';
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
        $this->checkClassExist($this->route);
        try {
            $reflectionClass = new ReflectionClass($this->className);
            $method = $reflectionClass->getMethod($this->methodName);
            $method->setAccessible(true);
            $method->invokeArgs(new $this->className(), ['body' => $this->_body]);
        } catch (ReflectionException $e) {

        }
    }

    /**
     * @param $route
     * @throws Exception
     */
    private function checkClassExist($route)
    {
        $routeArr = explode('/', $route);
        $this->moduleName = isset($routeArr[0]) ? strtolower($routeArr[0]) : '';
        $this->className = isset($routeArr[1]) ? ucfirst(strtolower($routeArr[1])) : '';
        $this->methodName = isset($routeArr[2]) ? strtolower($routeArr[2]) : $this->methodName;
        if (empty($this->moduleName) || empty($this->className)) {
            throw new Exception('Error Route:' . $this->route);
        }
        $this->classPath = dirname(dirname(__DIR__)) .
            '/' . strtolower($this->moduleName) .
            '/' . ucfirst(strtolower($this->className)) . '.php';
        if (!file_exists($this->classPath)) {
            throw new Exception('Error Route:' . $this->route);
        }
        require $this->classPath;
        if (!class_exists($this->className)) {
            throw new Exception('Error class not exist:' . $this->className);
        }
    }
}