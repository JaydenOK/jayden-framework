<?php

use app\core\lib\controller\Controller;

class EsToolDemoController extends Controller
{
    /**
     * 描述 : __construct
     * 作者 : Jayden
     */
    public function __construct()
    {
        $headers = $this->getHeaders();
        if (!isset($headers['Sign']) || $headers['Sign'] !== '') {
            header('HTTP/1.1 404 Not Found');
            exit;
        }
    }

    /**
     * 描述 :
     * 作者 : Jayden
     */
    public function getHeaders()
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else if (function_exists('http_get_request_headers')) {
            $headers = http_get_request_headers();
        } else {
            foreach ($_SERVER as $name => $value) {
                if (strncmp($name, 'HTTP_', 5) === 0) {
                    $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                    $headers[$name] = $value;
                }
            }
        }
        return $headers;
    }

    //创建es索引生命周期
    public function testCreateLifecyclePolicies()
    {
        $input = file_get_contents("php://input");
        $inputArr = json_decode($input, true);
        if (empty($inputArr['node']) || empty($inputArr['policiesName']) || empty($inputArr['dayConfig'])) {
            return ['info' => 'err'];
        }
        $node = of::config('env.' . $inputArr['node']);
        if (empty($node) || !is_array($node)) {
            return ['info' => 'node not found'];
        }
        $policiesName = $inputArr['policiesName'];
        return self::createLifecyclePolicies($node, $policiesName, $inputArr['dayConfig']);
    }

    //创建索引模版
    public function testCreateIndexTemplate()
    {
        $input = file_get_contents("php://input");
        $inputArr = json_decode($input, true);
        if (empty($inputArr['node']) || empty($inputArr['policiesName']) || empty($inputArr['index']) || empty($inputArr['mappings'])) {
            return ['info' => 'err'];
        }
        $node = of::config('env.' . $inputArr['node']);
        if (empty($node) || !is_array($node)) {
            return ['info' => 'node not found'];
        }
        $policiesName = $inputArr['policiesName'];
        $index = $inputArr['index'];
        $mappings = $inputArr['mappings'];
        return self::createIndexTemplate($node, $policiesName, $index, $mappings);
    }

    //查看索引结构
    public function testGetMapping()
    {
        $input = file_get_contents("php://input");
        $inputArr = json_decode($input, true);
        if (empty($inputArr['node']) || empty($inputArr['index'])) {
            return ['info' => 'err'];
        }
        $index = $inputArr['index'];
        $node = of::config('env.' . $inputArr['node']);
        if (empty($node) || !is_array($node)) {
            return ['info' => 'node not found'];
        }
        header("content-type: application/json");
        return self::getMapping($node, $index);
    }

    //更新索引结构
    public function testPutMapping()
    {
        $input = file_get_contents("php://input");
        $inputArr = json_decode($input, true);
        if (empty($inputArr['node']) || empty($inputArr['index']) || empty($inputArr['properties'])) {
            return ['info' => 'err'];
        }
        $index = $inputArr['index'];
        $properties = $inputArr['properties'];
        $node = of::config('env.' . $inputArr['node']);
        if (empty($node) || !is_array($node)) {
            return ['info' => 'node not found'];
        }
        return self::putMapping($node, $index, $properties);
    }

    //原始查询
    public function testSearchRaw()
    {
        $input = file_get_contents("php://input");
        $inputArr = json_decode($input, true);
        if (empty($inputArr['node']) || empty($inputArr['index'])) {
            return ['info' => 'index err'];
        }
        $node = of::config('env.' . $inputArr['node']);
        if (empty($node) || !is_array($node)) {
            return ['info' => 'node not found'];
        }
        unset($inputArr['node']);
        //除了node所有参数
        $res = self::searchRaw($node, $inputArr);
        header("content-type: application/json");
        return $res;
    }

    //查询
    public function testEsSearch()
    {
        $input = file_get_contents("php://input");
        $inputArr = json_decode($input, true);
        if (empty($inputArr['node']) || empty($inputArr['index']) || empty($inputArr['queryBody'])) {
            return ['info' => 'err'];
        }
        $index = $inputArr['index'];
        $queryBody = $inputArr['queryBody'];
        $node = of::config('env.' . $inputArr['node']);
        if (empty($node) || !is_array($node)) {
            return ['info' => 'node not found'];
        }
        //查询字段原则，字段field为key
        header("content-type: application/json");
        if (isset($inputArr['searchType']) && $inputArr['searchType'] == 'searchAfter') {
            $res = self::esSearchAfter($node, $index, $queryBody);
        } else if (isset($inputArr['searchType']) && $inputArr['searchType'] == 'searchScroll') {
            $res = self::esSearchScroll($node, $index, $queryBody, true, 10, 1, [], $inputArr['scrollId'] ?? '');
        } else {
            $res = self::esSearch($node, $index, $queryBody);
        }
        return $res;
    }

    //查找
    public function testFindOne()
    {
        $input = file_get_contents("php://input");
        $inputArr = json_decode($input, true);
        if (empty($inputArr['node']) || empty($inputArr['index']) || empty($inputArr['queryBody'])) {
            return ['info' => 'err'];
        }
        $index = $inputArr['index'];
        $queryBody = $inputArr['queryBody'];
        $node = of::config('env.' . $inputArr['node']);
        if (empty($node) || !is_array($node)) {
            return ['info' => 'node not found'];
        }
        //查询字段原则，字段field为key
        header("content-type: application/json");
        $res = self::findOne($node, $index, $queryBody);
        return $res;
    }

    //批量插入
    public function testInsertMany()
    {
        $input = file_get_contents("php://input");
        $inputArr = json_decode($input, true);
        if (empty($inputArr['node']) || empty($inputArr['index']) || empty($inputArr['data'])) {
            return ['info' => 'err'];
        }
        $index = $inputArr['index'];
        $data = $inputArr['data'];
        $node = of::config('env.' . $inputArr['node']);
        if (empty($node) || !is_array($node) || !is_array($data)) {
            return ['info' => 'node not found'];
        }
        header("content-type: application/json");
        $res = self::insertMany($node, $index, $data, true);
        return $res;
    }

    //批量更新(根据id批量更新)
    public function testUpdateMany()
    {
        $input = file_get_contents("php://input");
        $inputArr = json_decode($input, true);
        if (empty($inputArr['node']) || empty($inputArr['index']) || empty($inputArr['data'])) {
            return ['info' => 'err'];
        }
        $index = $inputArr['index'];
        $data = $inputArr['data'];
        $node = of::config('env.' . $inputArr['node']);
        if (empty($node) || !is_array($node) || !is_array($data)) {
            return ['info' => 'node not found'];
        }
        //查询字段原则，字段field为key
        header("content-type: application/json");
        $res = self::updateMany($node, $index, $data);
        return $res;
    }

    //删除
    public function testDelete()
    {
        $input = file_get_contents("php://input");
        $inputArr = json_decode($input, true);
        if (empty($inputArr['node']) || empty($inputArr['index']) || empty($inputArr['_id'])) {
            return ['info' => 'err'];
        }
        $index = $inputArr['index'];
        $_id = $inputArr['_id'];
        $refresh = $inputArr['refresh'] ?? false;
        $node = of::config('env.' . $inputArr['node']);
        if (empty($node) || !is_array($node) || empty($_id)) {
            return ['info' => 'node not found'];
        }
        header("content-type: application/json");
        $res = self::delete($node, $index, $_id, $refresh);
        return $res;
    }

    //批量删除
    public function testDeleteMany()
    {
        $input = file_get_contents("php://input");
        $inputArr = json_decode($input, true);
        if (empty($inputArr['node']) || empty($inputArr['index']) || empty($inputArr['queryBody'])) {
            return ['info' => 'err'];
        }
        $index = $inputArr['index'];
        $queryBody = $inputArr['queryBody'];
        $node = of::config('env.' . $inputArr['node']);
        if (empty($node) || !is_array($node) || !is_array($queryBody)) {
            return ['info' => 'node not found'];
        }
        header("content-type: application/json");
        $res = self::deleteByQuery($node, $index, $queryBody);
        echo json_encode($res);
    }

    //创建普通索引
    public function testCreateIndex()
    {
        $input = file_get_contents("php://input");
        $inputArr = json_decode($input, true);
        if (empty($inputArr['node']) || empty($inputArr['index']) || empty($inputArr['mappings']['properties'])) {
            return ['info' => 'err'];
        }
        $index = $inputArr['index'];
        $mappings = $inputArr['mappings'];
        $numberOfShards = $inputArr['number_of_shards'];
        $numberOfReplicas = $inputArr['number_of_replicas'] ?? 1;
        $node = of::config('env.' . $inputArr['node']);
        if (empty($node) || !is_array($node)) {
            return ['info' => 'node not found'];
        }
        header("content-type: application/json");
        $res = self::createIndex($node, $index, $mappings, $numberOfShards, $numberOfReplicas);
        return $res;
    }

    //删除索引
    public function testDeleteIndex()
    {
        $input = file_get_contents("php://input");
        $inputArr = json_decode($input, true);
        if (empty($inputArr['node']) || empty($inputArr['index'])) {
            return ['info' => 'err'];
        }
        $index = $inputArr['index'];
        $node = of::config('env.' . $inputArr['node']);
        if (empty($node) || !is_array($node)) {
            return ['info' => 'node not found'];
        }
        header("content-type: application/json");
        $res = self::deleteIndex($node, $index);
        return $res;
    }
}