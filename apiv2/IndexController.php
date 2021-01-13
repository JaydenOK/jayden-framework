<?php
/**
 * api模块2,
 */

namespace app\apiv2;

use app\core\lib\controller\Controller;
use app\language\Api;
use app\module\utils\ResponseUtil;

class IndexController extends Controller
{

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
        $data = ['test data'];
        return ResponseUtil::getOutputArrayByCodeAndData(Api::SUCCESS, $data);
    }

}