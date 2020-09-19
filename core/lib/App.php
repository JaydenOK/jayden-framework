<?php

namespace app\core\lib;

use app\core\lib\exception\Exception;
use app\core\lib\exception\InvalidParamException;
use app\core\lib\exception\InvalidValueException;
use app\core\lib\Request;
use app\core\lib\traits\Singleton;
use ReflectionClass;
use ReflectionException;

class App
{

    /**
     * @var App $app
     */
    public static $app;
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
    /**
     * @var Config
     */
    private $config;

    public static function getInstance()
    {
        if (is_null(static::$app)) {
            static::$app = new static();
        }
        return static::$app;
    }

    private function __construct($config = [])
    {
        //将函数注册到SPL __autoload函数队列中。如果该队列中的函数尚未激活，则激活它们
        //autoload 方法不能出现异常信息，否则不会执行其它自动加载，如vendor的composer加载
        spl_autoload_register([$this, 'autoload'], true, true);
        $this->_body = Request::getBody();
        $this->initConfig($config);
        $this->initRoute();
    }

    private function initConfig(array $config)
    {
        $fileConfig = [];
        if (file_exists(APP_ROOT . '/config/config.php')) {
            $fileConfig = require APP_ROOT . '/config/config.php';
        }
        $this->config = new Config(array_merge($fileConfig, $config));
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
        try {
            $this->checkRoute($this->route);
            if (!class_exists($this->className)) {
                throw new Exception("控制器不存在:[{$this->className}]");
            }
            if (!method_exists($this->className, $this->methodName)) {
                throw new Exception("{$this->methodName}方法不存在:[{$this->className}->{$this->methodName}]");
            }
            $controller = new $this->className();
            $result = call_user_func_array([$controller, $this->methodName], ['body' => $this->_body]);
            $this->handleResult($result);
//            $reflectionClass = new ReflectionClass($this->className);
//            $method = $reflectionClass->getMethod($this->methodName);
//            $method->setAccessible(true);
//            $method->invokeArgs(new $this->className(), ['body' => $this->_body]);
        } catch (InvalidParamException $e) {
            $this->handleException($e);
        } catch (InvalidValueException $e) {
            $this->handleException($e);
        } catch (Exception $e) {
            $this->handleException($e);
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

    public function getConfig()
    {
        return $this->config;
    }

    protected function autoload($class)
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
        return false;
    }

    private function handleException($e)
    {
        if (!DEBUG) {
            $code = $e->getCode();
            $output = $this->getOutput($code, $this->translate($code));
            $this->handleResult($output);
            exit(0);
        }
        print_r($e);
    }

    private function getOutput(int $code, $message = '')
    {
        $output = array(
            'code' => $code,
            'message' => $message,
        );
        return $output;
    }

    public function translate($code)
    {
        return Language::getMessage($code);
    }

    private function handleResult($result)
    {
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit(0);
    }

}