<?php

namespace app\system;

use app\core\lib\controller\Controller;
use app\language\Api;
use app\module\utils\ResponseUtil;
use app\utils\HttpClient;
use app\utils\LoggerUtil;

class TimerController extends Controller
{
    protected $path =  __DIR__.'/data/timer';
    protected $cron = [];
    protected $nowTask = [];
    /**
     * 描述 : 初始化
     */
    public function init() 
    {
        // 创建数据目录
        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }
        
        // 加载静态任务配置
        $cronFile = $this->path . '/crontab.php';
        if (is_file($cronFile)) {
            $this->cron = include $cronFile;
        }
    }

    /**
     * 启动定时器
     * @link http://jayden.cc/system/timer/start
     */
    public function start()
    {
        echo "定时器启动...\n";
        $this->cron = include $this->path . '/crontab.php';
        // 主循环
        while (true) {
            
            // 检查静态任务（crontab）
            
            // 无任务时休眠
            $taskNum = 0;
            if (!$taskNum) {
                sleep(30);
            } else {
                sleep(1);  // 有任务时短暂休眠
            }
        }
    }

    /**
     * 执行任务
     */
    private function executeTask($task, $cArg) {
        // 记录当前任务
        $this->nowTask = array(
            'call' => $task['call'],
            'cArg' => $cArg
        );
        
        // 在Windows下使用异步执行，Linux下使用fork
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: 使用popen异步执行
            $cmd = 'php -r "';
            $cmd .= '$task = ' . var_export($task, true) . ';';
            $cmd .= '$cArg = ' . var_export($cArg, true) . ';';
            $cmd .= 'call_user_func($task[\'call\'], $task, $cArg);';
            $cmd .= '"';
            popen($cmd, 'r');
        } else {
            // Linux: 使用pcntl_fork
            if (function_exists('pcntl_fork')) {
                $pid = pcntl_fork();
                if ($pid == 0) {
                    // 子进程执行任务
                    try {
                        call_user_func($task['call'], $task, $cArg);
                    } catch (\Exception $e) {
                        echo "任务执行异常: " . $e->getMessage() . "\n";
                    }
                    exit(0);
                }
            } else {
                // 不支持fork，同步执行
                try {
                    call_user_func($task['call'], $task, $cArg);
                } catch (\Exception $e) {
                    echo "任务执行异常: " . $e->getMessage() . "\n";
                }
            }
        }
    }
}