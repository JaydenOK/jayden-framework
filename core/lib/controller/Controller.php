<?php

namespace app\core\lib\controller;

use app\core\lib\App;
use app\core\lib\exception\InvalidParamException;
use app\core\lib\Request;
use app\language\Api;
use app\module\utils\ResponseUtil;
use DI\Container;
use DI\ContainerBuilder;
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
    protected $app;
    /**
     * @var Container
     */
    protected $container;

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
//        $this->initContainer();
        $this->init();
    }

    /**
     * 应该从App基础类加载容器，通过call_user_func_array()执行控制器时注入
     * @throws \Exception
     */
    private function initContainer()
    {
        $containerBuilder = new ContainerBuilder();
        $config = config('dependencies');
        if (!empty($config) && is_array($config)) {
            $containerBuilder->addDefinitions($config);
            $this->container = $containerBuilder->build();
            $this->container->call();
        }
    }


    /**
     * 子类需要初始化，可重写init方法
     */
    protected function init()
    {
    }
}