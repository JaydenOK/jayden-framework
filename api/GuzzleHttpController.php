<?php
/**
 *  GuzzleHttp文档： https://docs.guzzlephp.org/en/stable/quickstart.html
 */

namespace app\api;

use app\core\lib\controller\Controller;
use app\language\Api;
use app\module\utils\ResponseUtil;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

class GuzzleHttpController extends Controller
{

    //GET请求
    public function get()
    {
        try {
            $client = new Client();
            $response = $client->request(
                'GET',
                'http://dc.yibainetwork.com:86/Stock/overseaStockList',
                [
                    'query' => ['warehouse_code' => 'GC_UK']
                ]
            );
            $contentType = $response->getHeader('Content-Type');
            $code = $response->getStatusCode();
            $body = $response->getBody();
            return ResponseUtil::getOutputArrayByCodeAndData(
                Api::SUCCESS,
                ['code' => $code, 'size' => $body->getSize(), 'Content-Type' => $contentType, 'response' => \GuzzleHttp\json_decode($body->getContents()),]
            );
        } catch (GuzzleException $e) {
            //Write Log
            print_r('请求异常：' . $e->getMessage());
            exit;
        }
    }

    public function post()
    {
        $data = json_decode('[{"transfer_no":"ALLOT2210722061583","type":1,"warehouse_out":"GC_UK","warehouse_in":"GC-uk-amazon","sku":"GB-QCMP2808-22","quantity":"10"}]', true);
        //$uri = 'http://dc.yibainetwork.com:86/OverseaStock/virtualTransfer';
        $uri = 'http://dp.yibai-it.com:10032/OverseaStock/virtualTransfer';
        try {
            $client = new Client();
            $response = $client->request('POST', $uri, ['headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'], 'body' => $data]);
            //$response = $client->request('POST', $uri, ['json' => json_encode($data)]);
            $body = $response->getBody();
            $code = $response->getStatusCode();
            return ResponseUtil::getOutputArrayByCodeAndData(Api::SUCCESS, compact('code', 'body'));
        } catch (GuzzleException $e) {
            //Write Log
            print_r($e->getMessage());
            exit;
        }
    }

    //异步请求
    public function getAsync()
    {
        try {
            $client = new Client();
            $promise = $client->requestAsync('GET', 'http://dc.yibainetwork.com:86/Stock/overseaStockList');
            $responseData = [];
            $promise->then(
                function (ResponseInterface $res) {
                    $responseData[] = $res->getBody()->getContents();
                },
                function (RequestException $e) {
                    //请求异常
                    echo $e->getMessage() . "\n";
                    echo $e->getRequest()->getMethod();
                });
            return ResponseUtil::getOutputArrayByCodeAndData(Api::SUCCESS, $responseData);
        } catch (GuzzleException $e) {
            //Write Log
            print_r('请求异常：' . $e->getMessage());
            exit;
        }
    }


}