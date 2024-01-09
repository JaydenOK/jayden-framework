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

    public function test()
    {
        //#查询
        //        GET /ims_eb_spu_change_stock/_search
        //{
        //    "size":100,
        //  "sort": [
        //    {
        //        "createTime": {
        //        "order": "asc"
        //      }
        //    }
        //  ]
        //}
        //
        //
        //GET /ims_eb_spu_change_stock/_search
        //{
        //    "query": {
        //    "range": {
        //        "createTime": {
        //            "eq": "2024-01-08 08:00:00",
        //        "lte": "2024-01-08 12:00:00"
        //      }
        //    }
        //  }
        //}
        //
        //GET /_sql
        //{
        //    "query": """
        //  SELECT * FROM ims_eb_spu_change_stock where createTime>'2024-01-08 08:00:00' and createTime<='2024-01-08 16:00:00'
        //  """
        //}
        //
        //#新增, （/_doc/1 使用自定义主键）
        //POST /ims_eb_spu_change_stock/_doc
        //{"accountCode": "a","accountName": "1","createTime": "2024-01-08 00:00:00"}
        //
        //#批量新增
        //POST /_bulk
        //{"create":{"_index":"ims_eb_spu_change_stock"}}
        //{"accountCode": "a","accountName": "2024-01-08 00:00:00","createTime": "2024-01-08 00:00:00"}
        //{"create":{"_index":"ims_eb_spu_change_stock"}}
        //{"accountCode": "a","accountName": "2024-01-08 04:00:00","createTime": "2024-01-08 04:00:00"}
        //{"create":{"_index":"ims_eb_spu_change_stock"}}
        //{"accountCode": "a","accountName": "2024-01-08 08:00:00","createTime": "2024-01-08 08:00:00"}
        //{"create":{"_index":"ims_eb_spu_change_stock"}}
        //{"accountCode": "a","accountName": "2024-01-08 12:00:00","createTime": "2024-01-08 12:00:00"}
        //{"create":{"_index":"ims_eb_spu_change_stock"}}
        //{"accountCode": "a","accountName": "2024-01-08 16:00:00","createTime": "2024-01-08 16:00:00"}
        //{"create":{"_index":"ims_eb_spu_change_stock"}}
        //{"accountCode": "a","accountName": "2024-01-08 20:00:00","createTime": "2024-01-08 20:00:00"}
        //{"create":{"_index":"ims_eb_spu_change_stock"}}
        //{"accountCode": "a","accountName": "2024-01-09 00:00:00","createTime": "2024-01-09 00:00:00"}
        //{"create":{"_index":"ims_eb_spu_change_stock"}}
        //{"accountCode": "a","accountName": "2024-01-08 11:11:11","createTime": "2024-01-08 11:11:11"}
        //
        //
        //#更新
        //POST /ims_auto_delete_test_index/1/_update
        //{
        //    "doc": {
        //    "name": "jiaqiangban gaolujie yagao"
        //  }
        //}
        //
        //
        //#更新 如果对同一个index/type/id 使用 PUT，后面的数据会覆盖前面的数据（save操作）
        //PUT /ims_auto_delete_test_index/_doc/1
        //{
        //    "accountCode": "test",
        //  "accountName": "市"
        //}
        //
        //#删除文档
        //DELETE /ims_auto_delete_test_index/_doc/2
        //
        //
        //## 查询索引结构
        //GET /ims_eb_spu_change_stock/_mapping
        //
        //
        //# 统计条数
        //GET  ims_eb_spu_change_stock/_count
        //{
        //}
        //
        //
        //
        //POST /_analyze
        //{
        //    "analyzer": "standard",
        //  "text": "我们看下实际的重量，然后重新更新一下这些数据要不然 我们后续的数据都有问题的"
        //}
        //
        //#批量更新
        //POST /_bulk
        //{"delete":{"_index":"test-index", "_type":"test-type", "_id":"1"}}
        //{"create":{"_index":"test-index", "_type":"test-type", "_id":"2"}}
        //{"test_field":"test2"}
        //{"index":{"_index":"test-index", "_type":"test-type", "_id":"1"}}
        //{"test_field":"test1"}
        //{"update":{"_index":"test-index", "_type":"test-type", "_id":"3", "_retry_on_conflict":"3"}}
        //{"doc":{"test_field":"bulk filed 3"}}
        //
        //# 有哪些类型的操作可以执行:
        //#（1）delete：删除一个文档，只要1个json串就可以了
        //#（2）create：PUT /index/type/id/_create；只创建新文档
        //#（3）index：普通的put操作，可以是创建文档，也可以是全量替换文档
        //#（4）update：执行的partial update操作，即 post 更新
        //
        //
        //GET /_search
        //{
        //    "query": {
        //    "fuzzy": {
        //        "name": "Accha"
        //    }
        //  }
        //}
        //
        //# 查看集群配置，ILM周期检查时间默认是10分钟检查一次
        //GET /_cluster/settings
        //
        //# 修改检查策略命令
        //PUT /_cluster/settings
        //{
        //    "transient": {
        //    "indices.lifecycle.poll_interval": "1m"
        //  }
        //}
        //
        //
        //
        //
        //
        //
        //
        //
        //### 5、如何仅保存最近100天的数据？
        //### 1）delete_by_query设置检索近100天数据；
        //### 2）执行forcemerge操作，手动释放磁盘空间。
        //### 删除脚本如下：
        //
        //GET /json
        //{
        //    "query": {
        //    "range": {
        //        "pt": {
        //            "lt": "now-100d",
        //        "format": "epoch_millis"
        //      }
        //    }
        //  }
        //}
        //
        //# -XPOST "http://192.168.1.101:9200/logstash_*/_delete_by_query?conflicts=proceed"
        //
        //
        //## merge脚本如下：
        //
        //### #!/bin/sh
        //
        //POST /_forcemerge?
        //### only_expunge_deletes=true&max_num_segments=1'
        //
        //
        //
        //
        //
        //
        //            GET ims_tk_spu_change_stock_test2/_count
        //{
        //}
    }
}