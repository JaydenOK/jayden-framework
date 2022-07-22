<?php

namespace app\api;

use app\core\lib\controller\Controller;
use app\utils\HttpClient;

class TestController extends Controller
{

    public function index()
    {
        $url = 'http://rest.java.yibainetwork.com/stockcenter/ybOverseaStock/main/overseaShelves';
        $requestType = 'POST';
        $httpClient = HttpClient::getInstance()->setUrl($url)->setRequestType($requestType)->setTimeout(30)->setConnectTimeout(10)->setHeader(["access_token" => "abc", "sign" => "123"]);
        $httpClient->execute();
        $code = $httpClient->getHttpCode();
        $response = $httpClient->getResponse();
        print_r(['code' => $code, 'response' => $response]);
    }

}