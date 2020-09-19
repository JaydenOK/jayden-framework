<?php

namespace app\core\lib\controller;

use app\core\lib\exception\InvalidParamException;
use app\language\Api;
use Rakit\Validation\Validator;

class Controller
{
    /**
     * @var array $rules
     */
    protected $rules;
    protected $validator;

    public function __construct()
    {
        if (is_array($this->rules) && !empty($this->rules)) {
            $this->validator = new Validator();
            $validation = $this->validator->make($_POST + $_FILES, $this->rules);
            $validation->validate();
            if ($validation->fails()) {
//                echo "<pre>";
//                print_r($validation->errors()->firstOfAll());
//                echo "</pre>";
//                exit;
                throw new InvalidParamException("参数错误", Api::RECORD_NOT_EXISTS);
            }
        }
        $this->init();
    }

    /**
     * 子类需要初始化，可重写init方法
     */
    public function init()
    {
    }
}