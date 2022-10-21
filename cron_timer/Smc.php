<?php
//php脚本将Liunx命令写入文件，timer定时取命令执行，限制权限及特定命令，执行期间加锁

namespace app\cron_timer;

class Smc
{

    //程序控制
    public function manageHandle()
    {
        $params = $this->getRequestParams();
        $command = isset($params['command']) ? trim($params['command']) : '';
        if (empty($command) || !in_array($command, ['start', 'stop', 'status'])) {
            http_response("请选择命令command: start|stop|status");
        }
        $smcCmd = dirname(APPPATH) . "/smc/smc_cmd.sh";
        $smcLock = dirname(APPPATH) . "/smc/smc.lock";
        $lock = file_get_contents($smcLock);
        $lock = trim($lock);
        if (!empty($lock)) {
            $return = ['status' => 0, 'error_message' => "lock now: {$lock}"];
        } else {
            $cmd = '/usr/local/php/bin/php ' . dirname(APPPATH) . '/index.php command Smc manage ' . $command;
            file_put_contents($smcCmd, $cmd);
            $return = ['status' => 1, 'data' => $cmd];
        }
        http_response($return);
    }

}