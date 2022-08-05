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
    protected $pathInfo;
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
    protected $_body;
    /**
     * @var Config
     */
    private $config;
    /**
     * @var \app\core\lib\Request
     */
    public $request;
    /**
     * @var Response
     */
    public $response;

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
        $this->initPathInfo();
    }

    private function initConfig(array $config)
    {
        $fileConfig = [];
        if (file_exists(APP_ROOT . '/config/config.php')) {
            $fileConfig = require APP_ROOT . '/config/config.php';
        }
        $this->config = new Config(array_merge($fileConfig, $config));
    }

    /**
     * 初始化路由参数
     * 兼容路由参数【route或r】
     */
    private function initPathInfo()
    {
        $this->pathInfo = $this->request->getPathInfo();
        if (empty($this->pathInfo)) {
            // welcome
            echo 'Hi, Jayden-Framework.';
            exit(0);
        }
    }

    public function run()
    {
        try {
            $this->checkRequestPath();
            if (!class_exists($this->className)) {
                throw new Exception("error request:{$this->request->getPathInfo()}");
            }
            if (!method_exists($this->className, $this->action)) {
                throw new Exception("not exist:{$this->request->getPathInfo()}");
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
     * 检查路由，自动转换控制器名称第一个字符为大写，方法名第一个小写
     * http://jayden-framework.cc?route=Api/DiTest/test
     * @throws Exception
     */
    private function checkRequestPath()
    {
        $pathArr = explode('/', $this->pathInfo);
        $this->module = isset($pathArr[0]) ? strtolower($pathArr[0]) : '';
        $this->controller = isset($pathArr[1]) ? ucfirst($pathArr[1]) . $this->controllerSuffix : '';
        $this->action = isset($pathArr[2]) ? $pathArr[2] : $this->action;
        if (empty($this->module) || empty($this->controller)) {
            throw new Exception("error path:{$this->pathInfo}");
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
        if ($this->isCli()) {
            $argv = $_SERVER['argv'];
            $argv1 = isset($argv[1]) ? $argv[1] : '';
            $argv2 = isset($argv[2]) ? $argv[2] : '';
            if (!empty($argv1)) {
                $this->request->setPathInfo($argv1);
            }
            if (!empty($argv2)) {
                foreach (explode('&', $argv1) as $item) {
                    $keyValue = explode('=', $item);
                    if (isset($keyValue[0]) && !empty($keyValue[0])) {
                        $this->_body[$keyValue[0]] = isset($keyValue[1]) ? $keyValue[1] : null;
                    }
                }
            }
        } else {
            $this->_body = $this->request->getBodyArray();
        }
        return $this->_body;
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
            return;
        }
        print_r($e);
    }

    /**
     * @param int $code
     * @param string $message
     * @return array
     */
    private function getOutput(int $code, $message = '')
    {
        $output = array(
            'code' => $code,
            'message' => $message,
        );
        return $output;
    }

    /**
     * @param $code
     * @return array|mixed|null
     */
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

    /**
     * @param $result
     */
    public function handleResult($result)
    {
        $this->commonHeader();
        header('Content-Type: application/json');
        if (!is_null($result)) {
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * 是否在命令行执行
     * @return bool
     */
    function isCli()
    {
        if (PHP_VERSION_ID > 70200) {
            return (PHP_SAPI === 'cli');
        } else {
            return preg_match("/cli/i", php_sapi_name()) ? true : false;
        }
    }

}