<?php

namespace app\api;

use app\core\lib\controller\Controller;
use app\language\Api;
use app\module\utils\ResponseUtil;
use Rakit\Validation\Validator;

class ValidatorTestController extends Controller
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

    //测试验证器
    public function testValidator(array $params)
    {
        $validator = new Validator();
        $rules = [
            'account_name' => 'required|length:1,20',
            'account_s_name' => 'required|alpha_num',
            'account_email' => 'required|email',
            'site_id' => 'required|numeric|between:1,100',
            'developer_id' => 'required|numeric|in:1,2,3,4,5',
            'is_leave_account' => '|in:1,2',
            'leave_time' => 'required|date',
        ];
        $validator->setMessages([
            'required' => ':attribute是必填的',
            'numeric' => ':attribute必须是数字',
            'between' => ':attribute必须在:min~:max之间',
            'min' => ':attribute不能少于:min',
            'max' => ':attribute需大于:max',
            'in' => ':attribute只能是:allowed_values',
            'length' => ':attribute长度在:min~:max',
        ]);
        $validator->setTranslations([
            'and' => '和',
            'or' => '或者',
            'in' => '在',
            'between' => '在范围',
        ]);
        $validation = $validator->make($params, $rules);
        $validation->setAliases([
            'account_name' => '【账号名称】',
            'account_s_name' => '【账号简称】',
            'account_email' => '【店铺邮箱】',
            'site_id' => '【站点】',
            'developer_id' => '【开发者】',
            'is_leave_account' => '【是否休假账号】',
            'leave_time' => '【休假时间】',
        ]);
        $validation->validate();
        if ($validation->fails()) {
            $errors = $validation->errors();
            $return = $errors->firstOfAll();
        } else {
//            $return = $validation->getValidData();
            $return = 'success';
        }
        return ResponseUtil::getOutputArrayByCodeAndData(Api::SUCCESS, $return);
    }


}