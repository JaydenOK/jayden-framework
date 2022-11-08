<?php

/**
 * Swoole\Server 4.4+
 * Class SwooleServer
 */
class SwooleServer
{

    const EVENT_START = 'start';
    const EVENT_CONNECT = 'connect';
    const EVENT_CLOSE = 'close';
    const EVENT_TASK = 'task';
    const EVENT_FINISH = 'finish';
    const EVENT_MANAGER_START = 'managerStart';
    const EVENT_WORKER_START = 'workerStart';
    const EVENT_RECEIVE = 'receive';

    protected $_masterPid = null;
    protected $_managerPid = null;
    protected $_masterProcessName = null;
    protected $_managerProcessName = null;
    protected $_workerProcessName = null;
    /**
     * @var swoole_server
     */
    protected $_server = null;
    protected $_host = '0.0.0.0';
    protected $_port = '6000';
    protected $_configs = [
        'worker_num' => 1,
        'task_worker_num' => 1,
        'max_conn ' => 1000,
        'daemonize' => true,
        'max_request' => 1000,
        'task_ipc_mode' => 2,
        'task_max_request' => 1000,
        'dispatch_mode' => 1
    ];
    protected $_errorMessage = null;
    protected $_bindEventChain = [];
    /**
     * @var int
     */
    private $tableSize = 0;
    /**
     * @var array
     */
    private $tableColumns = [];
    /**
     * @var bool
     */
    private $isCreateTable = false;

    public function __construct($configs = [])
    {
        if (!is_array($configs))
            $configs = [];
        $this->_configs = array_merge($this->_configs, $configs);
        if (isset($configs['host']))
            $this->_host = $configs['host'];
        if (isset($configs['port']))
            $this->_port = $configs['port'];
    }

    public function setHost($host)
    {
        //@TODO ip 
        $this->_host = $host;
    }

    public function setPort($port)
    {
        //@TODO port 
        $this->_port = (int)$port;
    }

    public function setProcessName($name = '')
    {
        $this->_masterProcessName = $name . ':master';
        $this->_managerProcessName = $name . ':manager';
        $this->_workerProcessName = $name . ':worker';
    }

    public function startServer()
    {
        try {
            $this->_server = new swoole_server($this->_host, $this->_port);
            $this->createSwooleTable();
            if (!$this->_server)
                return false;
            $this->_server->set($this->_configs);
            $this->_initEvent();
            return $this->_server->start();
        } catch (Exception $e) {
            echo 'startServer Exception:' . $e->getMessage();
            return false;
        }
    }

    public function registryEvent($event, $callbacks = null)
    {
        if (!is_callable($callbacks))
            return false;
        $this->_server->on($event, $callbacks);
        return true;
    }

    protected function _initEvent()
    {
        $this->registryEvent(self::EVENT_START, [$this, 'onStart']);          //server start event
        //$this->registryEvent(self::EVENT_CONNECT, [$this, 'onConnect']);      //connections event
        $this->registryEvent(self::EVENT_TASK, [$this, 'onTask']);          //server start event
        $this->registryEvent(self::EVENT_FINISH, [$this, 'onFinish']);      //connections event
        //$this->registryEvent(self::EVENT_CLOSE, [$this, 'onClose']);      //client close connect event
        $this->registryEvent(self::EVENT_MANAGER_START, [$this, 'onManagerStart']);      //manager process start event
        $this->registryEvent(self::EVENT_WORKER_START, [$this, 'onWorkerStart']);      //worker process start event
        $this->registryEvent(self::EVENT_RECEIVE, [$this, 'onReceive']);      //worker process start event
    }

    public static function test()
    {
        echo 'test';
    }

    /**
     * @desc 服务启动回调函数
     * @param swoole_server $server
     */
    public function onStart(swoole_server $server)
    {
        if (!is_null($this->_masterProcessName))
            cli_set_process_title($this->_masterProcessName) || swoole_set_process_name($this->_masterProcessName);

        //@TODO 
//        if (array_key_exists(self::EVENT_START, $this->_bindEventChain)) {
//            foreach ($this->_bindEventChain[self::EVENT_START] as $callback) {
//                if (!is_callable($callback)) continue;
//                call_user_func($callback, $this);
//            }
//        }
        if (isset($this->_bindEventChain[self::EVENT_START][0]) && is_callable($this->_bindEventChain[self::EVENT_START][0])) {
            call_user_func($this->_bindEventChain[self::EVENT_START][0], $this);
        }
    }

    public function onConnect(swoole_server $server, $fd, $reactorId)
    {
        //@TODO
//        if (array_key_exists(self::EVENT_CONNECT, $this->_bindEventChain)) {
//            foreach ($this->_bindEventChain[self::EVENT_START] as $callback) {
//                if (!is_callable($callback)) continue;
//                call_user_func($callback, $this, $server, $fd, $reactorId);
//            }
//        }
        if (isset($this->_bindEventChain[self::EVENT_CONNECT][0]) && is_callable($this->_bindEventChain[self::EVENT_CONNECT][0])) {
            call_user_func($this->_bindEventChain[self::EVENT_CONNECT][0], $this, $server, $fd, $reactorId);
        }
    }

    public function onReceive(swoole_server $server, $fd, $reactor_id, $data)
    {
        //@TODO
//        if (array_key_exists(self::EVENT_RECEIVE, $this->_bindEventChain)) {
//            foreach ($this->_bindEventChain[self::EVENT_RECEIVE] as $callback) {
//                if (!is_callable($callback)) continue;
//                call_user_func($callback, $this, $fd, $reactor_id, $data);
//            }
//        }
        if (isset($this->_bindEventChain[self::EVENT_RECEIVE][0]) && is_callable($this->_bindEventChain[self::EVENT_RECEIVE][0])) {
            call_user_func($this->_bindEventChain[self::EVENT_RECEIVE][0], $this, $fd, $reactor_id, $data);
        }
    }

    public function onClose(swoole_server $server, int $fd, int $reactorId)
    {
        //@TODO
//        if (array_key_exists(self::EVENT_CLOSE, $this->_bindEventChain)) {
//            foreach ($this->_bindEventChain[self::EVENT_CLOSE] as $callback) {
//                if (!is_callable($callback)) continue;
//                call_user_func($callback, $this, $fd, $reactorId);
//            }
//        }
        if (isset($this->_bindEventChain[self::EVENT_CLOSE][0]) && is_callable($this->_bindEventChain[self::EVENT_CLOSE][0])) {
            call_user_func($this->_bindEventChain[self::EVENT_CLOSE][0], $this, $fd, $reactorId);
        }
    }

    public function onTask(swoole_server $serv, $taskId, $srcWorkerId, $data)
    {
        //@TODO
//        if (array_key_exists(self::EVENT_TASK, $this->_bindEventChain)) {
//            foreach ($this->_bindEventChain[self::EVENT_TASK] as $callback) {
//                if (!is_callable($callback)) continue;
//                call_user_func($callback, $this, $taskId, $srcWorkerId, $data);
//            }
//        }
        //改为限制一个回调，返回数据回worker
        if (isset($this->_bindEventChain[self::EVENT_TASK][0]) && is_callable($this->_bindEventChain[self::EVENT_TASK][0])) {
            return call_user_func($this->_bindEventChain[self::EVENT_TASK][0], $this, $taskId, $srcWorkerId, $data);
        }
    }

    public function onFinish(swoole_server $serv, $taskId, $data)
    {
        //@TODO
//        if (array_key_exists(self::EVENT_FINISH, $this->_bindEventChain)) {
//            foreach ($this->_bindEventChain[self::EVENT_FINISH] as $callback) {
//                if (!is_callable($callback)) continue;
//                call_user_func($callback, $this, $taskId, $data);
//            }
//        }
        if (isset($this->_bindEventChain[self::EVENT_FINISH][0]) && is_callable($this->_bindEventChain[self::EVENT_FINISH][0])) {
            call_user_func($this->_bindEventChain[self::EVENT_FINISH][0], $this, $taskId, $data);
        }

    }

    public function onManagerStart(swoole_server $serv)
    {
        if (!is_null($this->_managerProcessName))
            cli_set_process_title($this->_managerProcessName) || swoole_set_process_name($this->_managerProcessName);
        //@TODO
//        if (array_key_exists(self::EVENT_MANAGER_START, $this->_bindEventChain)) {
//            foreach ($this->_bindEventChain[self::EVENT_MANAGER_START] as $callback) {
//                if (!is_callable($callback)) continue;
//                @call_user_func($callback, $this);
//            }
//        }
        if (isset($this->_bindEventChain[self::EVENT_MANAGER_START][0]) && is_callable($this->_bindEventChain[self::EVENT_MANAGER_START][0])) {
            @call_user_func($this->_bindEventChain[self::EVENT_MANAGER_START][0], $this);
        }
    }

    public function onWorkerStart(swoole_server $serv)
    {
        if (!is_null($this->_workerProcessName))
            cli_set_process_title($this->_workerProcessName) || swoole_set_process_name($this->_workerProcessName);
        //@TODO
//        if (array_key_exists(self::EVENT_WORKER_START, $this->_bindEventChain)) {
//            foreach ($this->_bindEventChain[self::EVENT_WORKER_START] as $callback) {
//                if (!is_callable($callback)) continue;
//                @call_user_func($callback, $this);
//            }
//        }
        if (isset($this->_bindEventChain[self::EVENT_WORKER_START][0]) && is_callable($this->_bindEventChain[self::EVENT_WORKER_START][0])) {
            @call_user_func($this->_bindEventChain[self::EVENT_WORKER_START][0], $this);
        }
    }

    public function getMasterPid()
    {
        return $this->_server->master_pid;
    }

    public function getManagerPid()
    {
        return $this->_server->manager_pid;
    }

    public function getManagerProcessName()
    {
        return $this->_managerProcessName;
    }

    public function getMasterProcessName()
    {
        return $this->_masterProcessName;
    }

    /**
     * @desc 绑定服务回调事件额外的回调方法
     * @param string $event
     * @param callable $callback
     * @return boolean
     */
    public function bindEvent($event, $callback)
    {
        if (empty($event) || !is_callable($callback))
            return false;
        $this->_bindEventChain[$event][] = $callback;
        return true;
    }

    public function getLastError()
    {
        return $this->_server->getLastError();
    }

    public function getPort()
    {
        return $this->_server->port;
    }

    public function getHost()
    {
        return $this->_server->host;
    }

    public function getServer()
    {
        return $this->_server;
    }

    /**
     * @param int $size 参数指定表格的最大行数，必须为2的指数
     * @param array $columns
     * @return $this
     */
    public function setSwooleTableConfig($size = 8192, $columns = [])
    {
        $this->tableSize = $size;
        $this->tableColumns = $columns;
        return $this;
    }

    /**
     * 申请内存表
     * @param int $size
     * @param array $columns
     * @return \Swoole\Table|null
     */
    public function createSwooleTable()
    {
        if ($this->tableSize > 0 && is_array($this->tableColumns) && !empty($this->tableColumns)) {
            $table = new Swoole\Table($this->tableSize);
            foreach ($this->tableColumns as $column) {
                $table->column($column['name'], $column['type'], $column['len']);
            }
            $table->create();
            $this->isCreateTable = true;
            $this->_server->_table = $table;
            return $table;
        }
        return null;
    }

    /**
     * 获取已申请的内存表
     * @return Swoole\Table|null
     */
    public function getServerTable()
    {
        if ($this->isCreateTable) {
            return $this->_server->_table;
        }
        return null;
    }

}