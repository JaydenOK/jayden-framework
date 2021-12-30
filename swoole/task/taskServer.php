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
//Swoole\Table->set(string $key, array $value): bool ; $value必须是一个数组
$table = new Swoole\Table(10240);
//$table->column('id', Swoole\Table::TYPE_INT, 64);     //$key 为账号
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

//关联内存表到server使用
$server->table = $table;

$server->on('Start', function (Server $server) {
    echo date('[Y-m-d H:i:s]:') . 'Server Start' . PHP_EOL;
});

//@todo Receive 与 Finish 同一个worker 会相互阻塞
$server->on('Receive', function (Server $server, $fd, $reactorId, $data) {
    $platform = (int)$data;
    //@todo 模拟查询出的所有要执行的账号id
    $accountIdArr = [];
    for ($id = 1; $id <= 500; $id++) {
        $accountIdArr[] = $id;
    }
    $taskNum = count($accountIdArr);
    if ($taskNum == 0) {
        return false;
    }
    echo date('[Y-m-d H:i:s]:') . 'Receive--platform:' . $platform . '--taskNum:' . $taskNum . PHP_EOL;
    //@todo 在这里检测执行的任务数就可以了
    $taskWorkerNum = 20;
    foreach ($accountIdArr as $accountId) {
        while (true) {
            //当前执行任务数小于进程数，则投递新任务
            $runningCount = $server->table->count();
            if ($runningCount < $taskWorkerNum) {
                $task_id = $server->task($accountId);
                echo date('[Y-m-d H:i:s]:') . 'Receive--runningCount:' . $runningCount . '--taskId:' . $task_id . '--accountId:' . $accountId . PHP_EOL;
                if ($task_id) {
                    //投递无阻塞，投递成功，此处也记录内存表（可能task还未执行记录内存表）
                    //$server->table->set((string)$accountId, ['status' => 1, 'time' => time()]);
                    //继续投递下一个账号任务
                    break;
                } else {
                    echo date('[Y-m-d H:i:s]:') . 'Receive--Deliver Fail--taskId:' . $task_id . '--accountId:' . $accountId . PHP_EOL;
                }
            } else {
                //@todo 此日志数据量取决于超时任务执行时间
                //echo 'runningCount' . $runningCount . PHP_EOL;
            }
            //短暂延时，再检测是否可投递
            usleep(300);
        }
    }
});

$server->on('Task', function (Server $server, $taskId, $reactorId, $data) {
    $accountId = $data;
    $server->table->set((string)$accountId, ['status' => 1, 'time' => time()]);
    echo date('[Y-m-d H:i:s]:') . 'TaskStart--taskId:' . $taskId . '--accountId:' . $accountId . PHP_EOL;
    //返回任务执行的结果
    $result = (new App())->run($taskId, $accountId);
    echo date('[Y-m-d H:i:s]:') . 'TaskEnd--taskId:' . $taskId . '--accountId:' . $accountId . '--result:' . $result . PHP_EOL;
    //@todo 没有return返回或调用finish函数，不会触发OnFinish回调
    //$server->finish($data);
    //不返回worker
    $server->table->del((string)$accountId);
});

$server->on('Finish', function (Server $server, $taskId, $data) {
    $accountId = $data;
    echo date('[Y-m-d H:i:s]:') . 'Finish--taskId:' . $taskId . '--accountId:' . $accountId . PHP_EOL;
    $server->table->del((string)$accountId);
});

$server->on('Shutdown', function (Server $server) {
    echo date('[Y-m-d H:i:s]:') . 'Server Shutdown' . PHP_EOL;
});

$server->start();


class App
{
    public function run($taskId, $data)
    {
        //模拟耗时任务
        $sleepTime = mt_rand(0, 3);
        sleep($sleepTime);
        $return = '[OK-' . $taskId . '-' . $data . '-' . $sleepTime . ']';
        return $return;
    }
}