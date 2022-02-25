<?php
/**
 * Swoole Rsync Task Demo
 * Swoole异步任务 服务端 task任务，分批次处理异步任务，mworker无阻塞
 * 处理时间过长，可适当增大task_worker_num值
 */

use Swoole\Server;

$host = '127.0.0.1';
$port = 12002;
$taskWorkerNum = 20;

//创建内存表(类似于mysql表)。定义好表的结构后，执行 create 向操作系统申请内存，创建表。
//Swoole\Table->set(string $key, array $value): bool ; $value必须是一个数组，必须与字段定义的 $name 完全相同
$table = new Swoole\Table(10240);
$table->column('status', Swoole\Table::TYPE_INT, 4);
$table->column('time', Swoole\Table::TYPE_INT, 16);
$table->create();
$server = new Server($host, $port);
$logFile = __DIR__ . DIRECTORY_SEPARATOR . 'tmp.log';

//设置异步任务的工作进程数量
$server->set([
    'worker_num' => 1,
    'task_worker_num' => $taskWorkerNum,
    'max_conn ' => 1000,
    'daemonize' => true,
    'log_file' => $logFile,
    'max_request' => 1000,
    'task_ipc_mode' => 2,
    'task_max_request' => 1000,
    'dispatch_mode' => 1
]);

//关联内存表到server
/**
 * @var $server ->table Swoole\Table
 */
$server->table = $table;

$server->on('Start', function (Server $server) {
    $count = $server->table->count();
    echo date('[Y-m-d H:i:s]:') . 'Server-count:' . $count . PHP_EOL;
    echo date('[Y-m-d H:i:s]:') . 'Server-Start' . PHP_EOL;
});

//@todo Receive 与 Finish 同一个worker 会相互阻塞
$server->on('Receive', function (Server $server, $fd, $reactor_id, $data) {
    //$server->setting:{"worker_num":1,"task_worker_num":20,"max_conn ":1000,"daemonize":true,"log_file":"\/mnt\/yibai_dcm_order\/tmp.log","max_request":1000,"task_ipc_mode":2,"task_max_request":1000,"dispatch_mode":1,"buffer_output_size":2097152,"max_connection":65535}
    echo date('[Y-m-d H:i:s]:') . 'Server-Setting:' . json_encode($server->setting) . PHP_EOL;
    $platform = (int)$data;
    //@todo 模拟查询出的所有执行的账号id
    $accountIdArr = [];
    for ($id = 1; $id <= 400; $id++) {
        $accountIdArr[] = $id;
    }
    $taskNum = count($accountIdArr);
    if ($taskNum == 0) {
        return false;
    }
    echo date('[Y-m-d H:i:s]:') . 'platform:' . $platform . '--taskNum:' . $taskNum . PHP_EOL;


    $serverCount = $server->table->count();
    if ($serverCount > 0) {
        //清理历史缓存
        foreach ($server->table as $key => $row) {
            $server->table->del(strval($key));
            echo date('[Y-m-d H:i:s]:') . 'key:' . $key . ';row:' . json_encode($row) . PHP_EOL;
        }
    }

    foreach ($accountIdArr as $accountId) {
        $startTime = time();
        while (true) {
            $endTime = time();
            if ($endTime - $startTime > 8 * 60) {
                //超过时间不在投递此账号
                echo date('[Y-m-d H:i:s]:') . 'OverTime:' . $accountId . PHP_EOL;
                break;
            }
            $taskWorkerNum = $server->setting['task_worker_num'];
            $runningCount = $server->table->count();
            if ($runningCount < $taskWorkerNum) {
                $task_id = $server->task($accountId);
                echo date('[Y-m-d H:i:s]:') . 'runningCount:' . $runningCount . '--taskId:' . $task_id . '--accountId:' . $accountId . PHP_EOL;
                if ($task_id !== false) {
                    //投递成功，记录内存表
                    $server->table->set(strval($accountId), ['status' => 1, 'time' => time()]);
                    break;
                } else {
                    echo date('[Y-m-d H:i:s]:') . 'Deliver-Fail--taskId:' . $task_id . '--accountId:' . $accountId . PHP_EOL;
                }
            }
            usleep(300);
        }
    }

    //定时器是纯异步实现的
//    $server->tick(300, function ($timerId) use ($server, $fd, &$accountIdArr) {
//    });
    $runningCount = $server->table->count();
    echo date('[Y-m-d H:i:s]:') . 'Deliver-Done:' . $runningCount . PHP_EOL;
});

$server->on('Task', function (Server $server, $task_id, $reactor_id, $data) {
    //必须是一个数组，必须与字段定义的 $name 完全相同
    $accountId = $data;
    echo date('[Y-m-d H:i:s]:') . 'TaskStart--taskId:' . $task_id . '--accountId:' . $accountId . PHP_EOL;
    //返回任务执行的结果
    $result = (new App())->run($task_id, $accountId);
    echo date('[Y-m-d H:i:s]:') . 'TaskEnd--taskId:' . $task_id . '--accountId:' . $accountId . '--result:' . $result . PHP_EOL;
    //@todo 没有return返回或调用finish函数，不会触发OnFinish回调
    $res = $server->table->del(strval($accountId));
    $count = $server->table->count();
    echo date('[Y-m-d H:i:s]:') . 'TaskEnd--count:' . $count . '--accountId:' . $accountId . '--res:' . $res . PHP_EOL;
});


$server->on('Shutdown', function (Server $server) {
    $count = $server->table->count();
    echo date('[Y-m-d H:i:s]:') . 'Shutdown:' . $count . PHP_EOL;
    echo date('[Y-m-d H:i:s]:') . 'Server-Shutdown' . PHP_EOL;
});

$server->start();


class App
{
    public function run($task_id, $data)
    {
        //模拟耗时任务
        $sleepTime = mt_rand(0, 3);
        sleep($sleepTime);
        $return = '[OK-' . $task_id . '-' . $data . '-' . $sleepTime . ']';
        return $return;
    }
}