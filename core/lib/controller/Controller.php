<?php

namespace app\core\lib\controller;

use app\core\lib\App;
use app\core\lib\exception\InvalidParamException;
use app\core\lib\Request;
use app\language\Api;
use app\module\utils\ResponseUtil;
use Rakit\Validation\Validator;

class Controller
{

    /**
     * @var array $rules
     */
    protected $rules;
    protected $validator;
    /**
     * json
     * @var mixed
     */
    protected $body;
    /**
     * @var App
     */
    private $app;

    public function __construct()
    {
        $this->app = App::getInstance();
        $this->body = $this->app->getBody();
        if (is_array($this->rules) && !empty($this->rules)) {
            $this->validator = new Validator();
            $validation = $this->validator->make($this->body, $this->rules);
            $validation->validate();
            if ($validation->fails()) {
                $result = ResponseUtil::getOutputArrayByCodeAndData(Api::PARAM_ERROR, $validation->errors()->firstOfAll());
                $this->app->handleResult($result);
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