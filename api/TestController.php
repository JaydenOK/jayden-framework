<?php

namespace app\api;

use app\core\lib\controller\Controller;
use app\language\Api;
use app\module\utils\ResponseUtil;
use app\utils\HttpClient;
use app\utils\LoggerUtil;

class TestController extends Controller
{
    //测试接口
    public function testOne()
    {
        $url = 'https://api.ebay.com/commerce/taxonomy/v1/category_tree/0/get_item_aspects_for_category?category_id=1';
        $requestType = 'GET';
        $httpClient = HttpClient::getInstance()->setUrl($url)->setRequestType($requestType)->setTimeout(30)->setConnectTimeout(10)->setHeader(["access_token" => "abc", "sign" => "123"]);
        $httpClient->execute();
        $code = $httpClient->getHttpCode();
        $response = $httpClient->getResponse();
        //echo json(['code' => $code, 'response' => $response]);
        $data = [
            'reqParams' => $_REQUEST,
            'respData' => json_decode($response, true),
        ];
        return ResponseUtil::getOutputArrayByCodeAndData(Api::SUCCESS, $data);
    }

    public function loggerTest()
    {
        $logger = LoggerUtil::getLogger('', 'a.log');
        $logger = LoggerUtil::getLogger('', 'a.log');
        $logger->log('xxxxxxxx');
    }
}