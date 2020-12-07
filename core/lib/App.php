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
     * 请求路由参数,如 api/user/add，api/user（则执行api/user/run方法）
     * @var string
     */
    protected $route;
    /**
     * 模块（即目录名）
     * @var string
     */
    private $module;
    /**
     * @var string
     */
    private $controller;
    /**
     * 方法，默认执行run方法
     * @var string
     */
    private $action = 'run';
    /**
     * 控制器所在空间，有且只有第一个大写字母
     * @var
     */
    private $className;
    /**
     * 控制器后缀名称
     * @var string
     */
    private $controllerSuffix = 'Controller';
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
        $this->request = new Request();
        $this->response = new Response();
        $this->_body = $this->getBody();
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
            if (!method_exists($this->className, $this->action)) {
                throw new Exception("{$this->action}方法不存在:[{$this->className}->{$this->action}]");
            }
            $controller = new $this->className();
            $result = call_user_func_array([$controller, $this->action], ['body' => $this->_body]);
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
        $this->module = isset($routeArr[0]) ? strtolower($routeArr[0]) : '';
        $this->controller = isset($routeArr[1]) ? ucfirst(strtolower($routeArr[1])) . $this->controllerSuffix : '';
        $this->action = isset($routeArr[2]) ? strtolower($routeArr[2]) : $this->action;
        if (empty($this->module) || empty($this->controller)) {
            throw new Exception("路由错误:[{$this->route}]");
        }
        //加上命名空间
        $this->className = APP_NAME . '\\' . $this->module . '\\' . $this->controller;
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

    public function getBody()
    {
        if (!is_null($this->_body)) return $this->_body;
        return $this->_body = $this->request->getBodyArray();
    }

    /**
     * @return string
     */
    public function getModule()
    {
        return $this->module;
    }

    /**
     * @return string
     */
    public function getController()
    {
        return $this->controller;
    }

    /**
     * @return string
     */
    public function getAction()
    {
        return $this->action;
    }

    private function handleException($e)
    {
        if (!DEBUG) {
            //生产环境输出
            if ($e instanceof Exception) {
                $output = $this->getOutput(-1, $e->getMessage());
            } else {
                $code = $e->getCode();
                $output = $this->getOutput($code, $this->translate($code));
            }
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

    protected function commonHeader()
    {
        header('Access-Control-Allow-Headers: Origin, Accept, Content-Type, Authorization, ISCORS, createtime, platform, token, accesstoken, relativepath');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: POST, GET, PUT, OPTIONS, DELETE');
        if (isset($_SERVER['HTTP_ORIGIN'])) {
            header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
        }
    }

    public function handleResult($result)
    {
        $this->commonHeader();
        header('Content-Type: application/json');
        if (!is_null($result)) {
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
        }
        exit(0);
    }
}