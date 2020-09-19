<?php

namespace app\test;

use app\core\lib\controller\Controller;
use app\language\Api;
use app\module\utils\ResponseUtil;

class ValidateController extends Controller
{

    public function init()
    {
        //todo 替代构造方法

    }

    public $rules = [
        'name' => 'required',
        'email' => 'required|email',
//        'password' => 'required|min:6',
//        'confirm_password' => 'required|same:password',
//        'avatar' => 'required|uploaded_file:0,500K,png,jpeg',
    ];

    /**
     * 入口方法
     * @param $body
     * @return array
     */
    public function run($body)
    {
        //$this->body;为请求参数，也可在方法注入$body方式获取请求参数
        $data = ['id' => 325, 'name' => 'li明'];
        return ResponseUtil::getOutputArrayByCodeAndData(Api::SUCCESS, $data);
    }
}