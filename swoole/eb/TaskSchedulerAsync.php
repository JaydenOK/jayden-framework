<?php

class TaskSchedulerAsync
{
    /**
     * @desc swoole客户端连接
     * @var swoole_client
     */
    protected $_swooleClient = null;

    /**
     * swoole服务协议
     * @var null
     */
    protected $_swooleProtocol = null;

    /**
     * swoole服务主机地址
     * @var null
     */
    protected $_swooleServerHost = null;

    /**
     * @desc swoole服务端口
     * @var string
     */
    protected $_swooleServerPort = null;

    /**
     * 任务对象
     * @var TaskAbstract
     */
    protected $_task = null;

    /**
     * swoole服务配置参数
     * @var array
     */
    protected $_swooleConfigs = [
        'worker_num' => 1,
        'task_worker_num' => 10,
        'daemonize' => false
    ];

    /**
     * TaskScheduler constructor.
     * @param $task
     * @param array $swooleConfigs
     */
    public function __construct($task, $swooleConfigs = [])
    {
        $this->_task = $task;
        $this->_swooleConfigs = array_merge($this->_swooleConfigs, $swooleConfigs);
        $serverKey = $this->_task->generateSwooleServerKey();
        //设置swoole日志记录路径
        $logPath = LOG_DIR . 'swoole';
        if (!file_exists($logPath)) {
            mkdir($logPath, '0777', true);
        }
        $logName = $serverKey . '.log';
        $this->_swooleConfigs['log_file'] = $logPath . DIRECTORY_SEPARATOR . $logName;
    }

    /**
     * 执行任务
     * @return bool
     */
    public function runTask()
    {
        try {
            //1.检查任务对应的swoole服务是否启动，没有启动则启动服务
            $this->checkSwooleServer();
            //2.连接swoole服务
            $this->_swooleClient = SwooleServerManager::connectionServer($this->_swooleServerHost, $this->_swooleServerPort);
            if (empty($this->_swooleClient)) {
                throw new Exception('Connection Swoole Server Failed');
            }
            //3.发送任务到swoole服务器
            $this->sendTask($this->_task);
            return true;
        } catch (Exception $e) {
            echo date('[Y-m-d H:i:s]') . ' runTask: Exception, ' . var_export($e, true) . PHP_EOL;
        }
    }

    /**
     * 发送任务请求到swoole服务
     * @param $task
     */
    public function sendTask($task)
    {
        $taskData = serialize($task);
        $this->_swooleClient->send($taskData);
    }

    /**
     * 检查swoole服务是否启动
     * @return bool
     * @throws Exception
     */
    public function checkSwooleServer()
    {
        $serverKey = $this->_task->generateSwooleServerKey();
        //获取服务服务key对应的swoole服务信息
        $swooleServerModel = new SwooleServerModel();
        $swooleServerInfo = $swooleServerModel->findOne(['server_key' => $serverKey]);
        //没有相关swoole服务或者服务器没启动，启动swoole服务
        if (empty($swooleServerInfo)) {
            //保存swoole服务信息到mongodb
            $insertData = [
                'server_key' => $serverKey,
                'master_process_name' => '',
                'master_pid' => '',
                'manager_process_name' => '',
                'manager_pid' => '',
                'configs' => $this->_swooleConfigs,
                'create_time' => date('Y-m-d H:i:s'),
                'status' => SwooleServerManager::SERVER_UNKOWN,
                'update_time' => date('Y-m-d H:i:s'),
                'start_time' => '',
                'stop_time' => '',
                'host' => '',
                'port' => ''
            ];
            if (!$swooleServerModel->insertOne($insertData))
                throw new Exception('Save Swoole Server Faied');
            $masterPid = '';
        } else {
            $configs = $swooleServerInfo['configs'];
            $masterPid = $swooleServerInfo['master_pid'];
            $this->_swooleServerHost = $swooleServerInfo['host'];
            $this->_swooleServerPort = $swooleServerInfo['port'];
        }
        if (empty($masterPid) || !SwooleServerManager::serverIsRunning($swooleServerInfo['master_pid'])) {
            $server = SwooleServerManager::createSwooleServer($this->_swooleProtocol, $this->_swooleConfigs);
            if (!$server instanceof SwooleServer) {
                throw new Exception('Instance Swoole Server Failed');
            }
            $server->setProcessName($serverKey);
            //绑定swoole server事件
            $server->bindEvent(SwooleServer::EVENT_START, [$this, 'onStart']);
            $server->bindEvent(SwooleServer::EVENT_RECEIVE, [$this, 'onReceive']);
            $server->bindEvent(SwooleServer::EVENT_TASK, [$this, 'onTask']);
            $server->bindEvent(SwooleServer::EVENT_FINISH, [$this, 'onFinish']);
            $server->bindEvent(SwooleServer::EVENT_MANAGER_START, [$this, 'refreshServerInfo']);
            $columns = [
                ['name' => 'status', 'type' => Swoole\Table::TYPE_INT, 'len' => 2],
                ['name' => 'time', 'type' => Swoole\Table::TYPE_INT, 'len' => 13],
            ];
            $server->setSwooleTableConfig(8192, $columns);
            //启动服务监听等待
            if (!SwooleServerManager::launchServer($server)) {
                throw new Exception('Swoole Server Launch Failed');
            }
        }
        return true;
    }

    /**
     * 任务处理回调函数
     * @param $server SwooleServer
     * @param $taskId
     * @param $srcWorkerId
     * @param $data
     * @return mixed
     */
    public function onTask($server, $taskId, $srcWorkerId, $data)
    {
        //每个Task任务进程单独实例化mongodb类，解决mongodb多进程共享连接的bug
        try {
            $config = include CONFIG_DIR . ORG . '/mongodbConfig.php';
            $dsn = isset($config['dsn']) ? trim($config['dsn']) : '';
            $dbName = isset($config['db_name']) ? trim($config['db_name']) : '';
            //$db维护一个MongoDB\Client
            $db = Db::getInstance()->createConnection($dsn, $dbName);
        } catch (Exception $e) {
            echo 'Connection MongoDB Failed';
            exit;
        }
        try {
            /**
             * 注册全局变量db，在Model获取使用这个db
             *  $this->_db = Front::getGlobalVar('db');
             */
            Front::registryGlobalVar('db', $db);
            //注册任务观察者
            $this->_task->registerTaskObserver();
            //注册任务处理程序
            $this->_task->registerTaskWorker();
            //@TODO task进程处理任务事件
            echo date('[Y-m-d H:i:s]') . ' onTask-Start:taskId:' . $taskId . ', _id: ' . $data . PHP_EOL;
            $flag = $this->_task->runTask($data);
        } catch (Exception $e) {
            echo date('[Y-m-d H:i:s]') . ' onTask-Exception' . $e->getMessage() . PHP_EOL;
        }
        //删除内存表数据
        $server->getServerTable()->del(strval($data));
        $count = $server->getServerTable()->count();
        echo date('[Y-m-d H:i:s]') . ' table-count:' . $count . PHP_EOL;
    }

    /**
     * 服务启动回调函数
     * @param $server
     */
    public function onStart($server)
    {
        //@TODO 服务启动处理任务事件
        echo date('[Y-m-d H:i:s]') . ' Swoole Server Start' . PHP_EOL;
    }

    /**
     * swoole服务接受到数据后回调函数
     * @param $server SwooleServer
     * @param $fd
     * @param $reactorId
     * @param $data
     * @return bool
     */
    public function onReceive($server, $fd, $reactorId, $data)
    {
        echo date('[Y-m-d H:i:s]') . ' onReceive-Start:' . PHP_EOL;
        try {
            $task = unserialize($data);
            if (!$task instanceof TaskAbstract) {
                echo date('[Y-m-d H:i:s]') . ' onReceive:[Task Type Error]' . PHP_EOL;
                return false;
            }
            $this->_task = $task;
            $taskList = $task->getTaskList();
            echo date('[Y-m-d H:i:s]') . ' onReceive:taskCount:' . count($taskList) . PHP_EOL;

            //清理上一次任务超时task记录
            TaskModel::model()->getRunningTask($this->_task->platform, $this->_task->type);

            if (is_array($taskList) && !empty($taskList)) {
                foreach ($taskList as $key => $_id) {
                    //将任务修改为运行中
                    echo date('[Y-m-d H:i:s]') . ' onReceive:deliverTask:fd' . $fd . ',key:' . $key . ',_id:' . $_id . PHP_EOL;
                    $this->deliverTask($server, $_id);
                }
                $this->deliverRemainingTask($server);
            } else {
                echo date('[Y-m-d H:i:s]') . ' onReceive:No Task Received';
            }
        } catch (Exception $e) {
            echo date('[Y-m-d H:i:s]') . ' onReceive:Receive Task Exception:' . $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * 投递一个任务到task进程
     * @param $server SwooleServer
     * @param $_id
     * @param int $dstWorkerId
     * @return bool
     */
    public function deliverTask($server, $_id, $dstWorkerId = -1)
    {
        $taskModel = new TaskModel();
        if (is_string($_id)) {
            $_id = new MongoDB\BSON\ObjectId($_id);
        }
        //检查任务是否运行中
        $criteria = ['_id' => $_id];
        $taskInfo = $taskModel->findOne($criteria);
        if (!empty($taskInfo) && $taskInfo['status'] == TaskModel::STATUS_RUNNING) {
            return false;
        }
        //将任务更新为运行中
        $flag = $taskModel->updateOne($criteria, ['status' => TaskModel::STATUS_RUNNING, 'execute_time' => date('Y-m-d H:i:s')]);
        if ($flag) {
            //投递任务
            $swooleTaskId = $server->getServer()->task($_id);
            echo date('[Y-m-d H:i:s]') . ' deliverTask:_id:' . $_id . ',swooleTaskId:' . $swooleTaskId . PHP_EOL;
            if ($swooleTaskId === false) {
                //投递失败将任务更新为初始化
                $deliverTaskNum = isset($taskInfo['deliver_task_num']) ? $taskInfo['deliver_task_num'] + 1 : 1;
                $flag = $taskModel->updateOne($criteria, ['status' => TaskModel::STATUS_INIT, 'execute_time' => '', 'deliver_task_num' => $deliverTaskNum]);
            } else {
                $server->getServerTable()->set(strval($_id), ['status' => 1, 'time' => time()]);
                return true;
            }
        }
        return false;
    }

    /**
     * @param $server SwooleServer
     */
    public function deliverRemainingTask($server)
    {
        while (true) {
            //获取正在运行的任务，并将运行时间超过10分钟的任务标记成失败
            $runningTaskIdNum = $server->getServerTable()->count();
            $taskWorkerNum = $server->getServer()->setting['task_worker_num'];
            $canDeliverTaskIdNum = $taskWorkerNum - $runningTaskIdNum;
            echo date('[Y-m-d H:i:s]') . ' deliverRemainingTask:task_worker_num:' . $taskWorkerNum . ',runningTaskIdNum:' . $runningTaskIdNum . ',canDeliverTaskIdNum:' . $canDeliverTaskIdNum . PHP_EOL;
            if ($canDeliverTaskIdNum <= 0) {
                echo date('[Y-m-d H:i:s]') . ' remainRunNum:' . $canDeliverTaskIdNum . PHP_EOL;
                sleep(1);
                continue;
            }
            $mongoTaskIdArr = $this->_task->batchGetNextTask($canDeliverTaskIdNum);
            if (empty($mongoTaskIdArr)) {
                //@todo 没有任务停止循环
                echo date('[Y-m-d H:i:s]') . ' deliverRemainingTask:noTaskWaitingProcess' . PHP_EOL;
                break;
            }
            foreach ($mongoTaskIdArr as $mongoTaskId) {
                $this->deliverTask($server, $mongoTaskId);
            }
        }
    }

    /**
     * @param SwooleServer|null $server
     * @return bool
     */
    public function refreshServerInfo(SwooleServer $server = null)
    {
        $serverKey = $this->_task->generateSwooleServerKey();
        $swooleServerModel = new SwooleServerModel();
        $swooleServerInfo = $swooleServerModel->findOne(['server_key' => $serverKey]);
        if (!empty($swooleServerInfo)) {
            $filter = ['_id' => $swooleServerInfo->_id];
            $updateData = [
                'master_process_name' => $server->getMasterProcessName(),
                'manager_process_name' => $server->getManagerProcessName(),
                'master_pid' => $server->getMasterPid(),
                'manager_pid' => $server->getManagerPid(),
                'port' => $server->getPort(),
                'host' => $server->getHost(),
                'status' => SwooleServerManager::SERVER_RUNNING,
                'update_time' => date('Y-m-d H:i:s'),
                'start_time' => date('Y-m-d H:i:s'),
                'error_code' => $server->getLastError()
            ];
            return $swooleServerModel->updateOne($filter, $updateData);
        }
        return false;
    }


}
