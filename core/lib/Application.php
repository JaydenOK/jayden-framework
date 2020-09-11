<?php

class Application
{

    /**
     * @var string
     */
    protected $route;
    /**
     * @var array|null
     */
    private $_bodyParams;
    /**
     * @var string
     */
    private $_rawBody;
    /**
     * @var string 自定义方法
     */
    private $methodParam = '_method';

    private $classPath;
    private $_queryParams;
    /**
     * @var string
     */
    private $_method;

    public function __construct()
    {
        $this->_bodyParams = $this->getBodyParams();
        $this->initRoute();
    }

    private function initRoute()
    {
        if (!isset($this->_bodyParams['route']) || empty($this->_bodyParams['route']) || !is_string($this->_bodyParams['route'])) {
            // welcome
            echo 'Hi, john-utils!';
            exit(0);
        }
        $this->route = $this->_bodyParams['route'];
        unset($this->_bodyParams['route']);
    }

    public function run()
    {
        $this->checkClassExist($this->route);
        call_user_func_array($this->classPath, $this->_bodyParams);
    }

    /**
     * @param $route
     * @throws Exception
     */
    private function checkClassExist($route)
    {
        list($dir, $className) = explode('/', $route);
        if (empty($dir) || empty($className)) {
            throw new Exception('Error Route:' . $this->route);
        }
        $this->classPath = dirname(dirname(__DIR__)) . '/' . strtolower($dir) . '/' . ucfirst(strtolower($className)) . '.php';
        if(!file_exists($this->classPath)){
            throw new Exception('Error Route:' . $this->route);
        }
        include $this->classPath;
    }

    /**
     *  获取请求头信息
     * @return array|false
     */
    function getHeaders()
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } elseif (function_exists('http_get_request_headers')) {
            $headers = http_get_request_headers();
        } else {
            foreach ($_SERVER as $name => $value) {
                if (strncmp($name, 'HTTP_', 5) === 0) {
                    $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                    $headers[$name] = $value;
                }
            }
        }
        return $headers;
    }

    public function getBodyParams()
    {
        if ($this->_bodyParams === null) {
            if (isset($_POST[$this->methodParam])) {
                $this->_bodyParams = $_POST;
                unset($this->_bodyParams[$this->methodParam]);
                return $this->_bodyParams;
            }

            $contentType = $this->getContentType();
            if (($pos = strpos($contentType, ';')) !== false) {
                $contentType = substr($contentType, 0, $pos);
            }
            $this->_method = $this->getMethod();
            if ($this->_method === 'GET') {
                $this->_bodyParams = $this->getQueryParams();
            } else if ($this->_method === 'POST') {
                $this->_bodyParams = $_POST;
            } else {
                $this->_bodyParams = [];
                //mb_parse_str 解析 GET/POST/COOKIE 数据并设置全局变量，然后设置其值为 array 的 result 或者全局变量。
                mb_parse_str($this->getRawBody(), $this->_bodyParams);
            }
        }

        return $this->_bodyParams;
    }

    public function getQueryParams()
    {
        if ($this->_queryParams === null) {
            return $_GET;
        }
        return $this->_queryParams;
    }

    public function getContentType()
    {
        if (isset($_SERVER["CONTENT_TYPE"])) {
            return $_SERVER["CONTENT_TYPE"];
        } elseif (isset($_SERVER["HTTP_CONTENT_TYPE"])) {
            //fix bug https://bugs.php.net/bug.php?id=66606
            return $_SERVER["HTTP_CONTENT_TYPE"];
        }

        return null;
    }

    public function getRawBody()
    {
        if ($this->_rawBody === null) {
            $this->_rawBody = file_get_contents('php://input');
        }

        return $this->_rawBody;
    }

    public function getMethod()
    {
        if (isset($_POST[$this->methodParam])) {
            return strtoupper($_POST[$this->methodParam]);
        } elseif (isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
            return strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
        } else {
            return isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        }
    }
}