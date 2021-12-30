<?php

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

require 'bootstrap.php';

$httpServer = new Server('192.168.92.65', 12001);
$logFile = __DIR__ . DIRECTORY_SEPARATOR . 'server.log';
$setting = [
    'daemonize' => true,
    'log_file' => $logFile,
];
$httpServer->set($setting);
$httpServer->on('Request', function (Request $request, Response $response) {
    (new Application($request, $response))->run();
});
$httpServer->start();

class Application
{
    /**
     * @var Request
     */
    private $request;
    /**
     * @var Response
     */
    private $response;
    /**
     * @var string
     */
    private $class;
    private $method;

    public function __construct(Request $request, Response $response)
    {
        $this->request = $request;
        $this->response = $response;
    }

    private function getController(string $class)
    {
        return new $class($this->request, $this->response);
    }

    public function run()
    {
        try {
            $this->resolve();
            $data = $this->execute();
            $responseData = ['code' => 0, 'message' => 'success', 'data' => $data];
        } catch (Exception $e) {
            $responseData = ['code' => -1, 'message' => $e->getMessage(), 'data' => null];
        }
        $this->jsonResponse($responseData);
    }

    private function resolve()
    {
        $this->request->server['request_uri'];
        list($class, $method) = explode('/', trim($this->request->server['request_uri'], '/'));
        $this->class = ucfirst($class);
        $this->method = $method;
        if (empty($this->class) || empty($this->method)) {
            throw new Exception('Not Found');
        }
        if (!class_exists($this->class)) {
            throw new Exception(sprintf('class %s not exist', $this->class));
        }
        if (!method_exists($this->class, $this->method)) {
            throw new Exception(sprintf('method not exist: %s->%s', $this->class, $this->method));
        }
    }

    private function execute()
    {
        $responseData = $this->getController($this->class)->{$this->method}();
        return $responseData;
    }

    private function jsonResponse($responseData)
    {
        $this->response->header('Content-Type', 'application/json');
        $this->response->end(json_encode($responseData));
    }

}

class MyController
{

    /**
     * @var Request
     */
    protected $request;
    /**
     * @var Response
     */
    protected $response;

    final public function __construct(Request $request, Response $response)
    {
        $this->request = $request;
        $this->response = $response;
        $this->init();
    }

    protected function init()
    {
    }
}

/**
 * Class Test
 */
class Test extends MyController
{

    public function init()
    {
    }

    public function t1()
    {
        $obj = new stdClass();
        $obj->id = mt_rand(100000, 999999);
        $obj->updateTime = date('Y-m-d H:i:s');
        $obj->get = $this->request->get;
        $obj->post = $this->request->post;
        $obj->rawContent = $this->request->rawContent();
        $obj->header = $this->request->header;
        $obj->fd = $this->request->fd;
        return $obj;
    }


}