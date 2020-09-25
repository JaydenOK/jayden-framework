<?php

namespace app\api;

use app\core\lib\controller\Controller;
use app\language\Api;
use app\module\utils\ResponseUtil;

class UserController extends Controller
{
    /**
     * 定义rules，自动校验请求参数，是否符合规则
     * @var array
     */
    public $rules = [
//        'name' => 'required',
        'avatar' => 'required|uploaded_file:0,500K,png,jpeg',
        'email' => 'required|email',
//        'password' => 'required|min:6',
//        'confirm_password' => 'required|same:password',

    ];

    public function init()
    {
        //todo 替代构造方法
    }

    /**
     * 默认入口方法
     * @param $body
     * @return array
     */
    public function run($body)
    {
        $data = ['id' => 325, 'name' => 'li明'];
        return ResponseUtil::getOutputArrayByCodeAndData(Api::SUCCESS, $data);
    }

    public function add()
    {
        //$this->body;为请求参数，也可在方法注入$body方式获取请求参数]
        $data = ['id' => 325, 'name' => 'li明', 'route' => $this->app->getModule() . '/' . $this->app->getController() . '/' . $this->app->getAction()];
        return ResponseUtil::getOutputArrayByCodeAndData(Api::SUCCESS, $data);
    }

}