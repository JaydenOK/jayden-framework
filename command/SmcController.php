<?php


namespace app\command;

use app\core\lib\Config;
use app\core\lib\controller\Controller;
use app\language\Api;
use app\module\utils\ResponseUtil;
use Pupilcp\App;

//swoole-multi-consumer（简称smc-server）使用PHP实现，使用swoole多进程消费者订阅消息，实现自动监控队列的数量，自动伸缩消费者数量，
//并实现核心配置文件的热加载，目前暂时支持rabbitmq。

require_once THIRD_PARTY_DIR . '/swoole_multi_consumer_bootstrap.php';

class SmcController extends Controller
{

    //命令行执行: php index.php "r=command/Smc/start&param1=1&param2=2"
    public function start()
    {
        $globalConfig = include APP_ROOT . '/config/smc/globalConfig.php';
        try {
            $app = new App($globalConfig);
            $app->run();
        } catch (\Throwable $e) {
            //处理异常情况 TODO
            return ResponseUtil::getOutputArrayByCodeAndData(Api::SYSTEM_EXCEPTION, $e->getMessage());
        }
    }


}