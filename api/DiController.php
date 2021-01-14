<?php
/**
 * 使用第三方扩展DI，实现自动注入 (未实现)
 * 应该从App基础类加载容器属性，通过call_user_func_array()执行控制器时注入 (有空再改造)
 */

namespace app\api;

use app\core\lib\controller\Controller;
use app\language\Api;
use app\module\utils\ResponseUtil;
use app\service\di\Mailer;
use app\service\di\CacheManager;
use DI\Container;

class DiController extends Controller
{

    /**
     * @var CacheManager
     */
    private $userManager;

    public function init()
    {
        //todo 替代构造方法
        $this->userManager = new CacheManager(new Mailer());

    }

    public function show()
    {
        $this->userManager->register('603480498@qq.com', 'aaaaaaa');
    }

    public function simpleDi()
    {
        
    }
}