<?php

namespace serv\rapi;

use of;
use Elasticsearch;

class esTool
{

    public static $errorMessage;

    /**
     * 描述: 执行ES-SQL查询
     * 作者: Jayden
     */
    public static function esSearchBySql($sql, $nodes = 'esList', $size = 5000)
    {
        $clientListing = \Elasticsearch\ClientBuilder::create()->setHosts($nodes)->build();
        $result = [];
        $i = 0;
        $columns = [];
        while (true) {
            $params = [
                'body' => [
                    'query' => $sql,
                    'cursor' => $queryResult['cursor'] ?? '',
                    'fetch_size' => $size,
                ],
            ];
            $queryResult = $clientListing->sql()->query($params);
            if (!empty($queryResult['columns'])) {
                $columns = $queryResult['columns'];
            }
            if (empty($queryResult['rows'])) {
                break;
            }
            foreach ($queryResult['rows'] as $rows) {
                foreach ($rows as $k => $v) {
                    $result[$i][$columns[$k]['name']] = $v;
                }
                $i++;
            }
            if (empty($queryResult['cursor'])) {
                break;
            }
        }
        return $result;
    }

    /**
     * 描述: 查找一个记录(基于主键_id，或唯一键查询)
     * 作者: Jayden
     */
    public static function findOne($node, $index, $queryBody, $sourceFields = true)
    {
        if (empty($queryBody)) {
            return [];
        }
        $client = Elasticsearch\ClientBuilder::create()
            ->setHosts($node)
            ->build();
        $body = [];
        $body['query'] = $queryBody;
        $pageSize = 1;
        $page = 1;
        $params = [
            'index' => $index,
            'body' => $body,
            '_source' => $sourceFields,
            'from' => ($page - 1) * $pageSize,
            'size' => $pageSize
        ];
        $results = $client->search($params);
        //$results = $client->get($params);
        $return = [
            'total' => $results['hits']['total']['value'] ?? 0,       //总记录数
            'records' => [],    //当前返回记录数
        ];
        if (isset($results['hits']) && !empty($results['hits'])) {
            foreach ($results['hits']['hits'] as $row) {
                $return['records'][] = array_merge(['_id' => $row['_id']], $row['_source']);
            }
        }
        return $return;
    }

    /**
     * 描述: 更新记录。_id不存在不更新，可指定更新部分数据
     * @var $retryOnConflict int 并发冲突，重试次数
     * 作者: Jayden
     */
    public static function updateOne($node, $index, $data, $id, $retryOnConflict = null)
    {
        try {
            $client = Elasticsearch\ClientBuilder::create()
                ->setHosts($node)
                ->build();
            $params = [
                'index' => $index,
                'id' => $id,
                'retry_on_conflict' => $retryOnConflict ?? 3,
                'body' => ['doc' => $data],
            ];
            $result = $client->update($params);
            return $result['_id'] ?? null;
        } catch (\Exception $e) {
            //MEMO日志类型
            self::$errorMessage = $e->getMessage();
            //记录日志
            of::event('of::error', true, [
                'memo' => true,
                'code' => E_USER_ERROR,
                'info' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return false;
        }
    }

    /**
     * 描述: 批量更新
     * 作者: Jayden
     */
    public static function updateMany($node, $index, $data)
    {
        //todo
        return true;
    }

    /**
     * 描述: ES查询
     * @param $node array 节点
     * @param $index string 索引
     * @param $queryBody array 条件
     * @param $sourceFields mixed 指定返回字段 ，true默认返回所有    $sourceFields = ['imsUUID','accountCode','accountId'];
     * @param $pageSize int 每页返回数
     * @param $page int 查询起始页，默认第一页
     * @param $sortArr mixed 排序  $sortArr = 'createTime DESC, spu ASC';
     * @return array
     *
     * //$queryBody = [
     * //    'spu' => 'MMM0181891',
     * //    'siteCode' => 'Germany',
     * //    'productId !=' => '404631367910',
     * //    'state' => [
     * //        'in' => [20, 40],
     * //    ],
     * //    'accountName' => [
     * //        'not_in' => ['2010wonderinthebox', 'easycloud1'],
     * //    ],
     * //    'imsUUID' => [
     * //        'eq' => 'CTK23120404144300006',
     * //    ],
     * //    //不等于
     * //    'productId' => [
     * //        'neq' => '404631367910',
     * //    ],
     * //    'createTime' => [
     * //        'lt' => '2023-12-04 04:14:43',
     * //        'gte' => '2023-12-04 04:14:41',
     * //    ],
     * //    'updateTime' => [
     * //        'gte' => date('Y-m-d 00:00:00'),
     * //        'lte' => date('Y-m-d 23:00:00'),
     * //    ],
     * //    'data' => [
     * //        'like' => '00004',
     * //    ],
     * //    'accountName2' => [
     * //        'like' => '10tobebet',
     * //    ],
     * //    'accountName3' => [
     * //        'not_like' => '10tobebet',
     * //    ],
     * //    'data2' => [
     * //        'like' => ['CTK2312040', 'prefix|wildcard|regexp|fuzzy'],
     * //    ],
     * //];
     *
     * 作者: Jayden
     */
    public static function esSearch($node, $index, $queryBody, $sourceFields = true, $pageSize = 10, $page = 1, $sortArr = [])
    {
        $client = \Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
        $page = intval($page);
        $pageSize = intval($pageSize);
        $body = [];
        if (!empty($sortArr)) {
            $bodySort = self::bodySort($sortArr);
            if (!empty($bodySort)) {
                $body['sort'] = $bodySort;
            }
        }
        if (!empty($queryBody)) {
            //需要考虑文档和搜索词的相关性（有分数），那么使用query; filter不需要计算相关性而且会缓存结果，有更好的性能
            //term不分词，match、match_phrase分词
            //keyword不分词，text分词
            $body['query'] = self::expandQuery($queryBody);
        }
        $params = [
            'index' => $index,
            'body' => $body,
            '_source' => $sourceFields,
            'from' => ($page - 1) * $pageSize,
            'size' => $pageSize
        ];
        $results = $client->search($params);
        $countParams = $params;
        unset($countParams['from'], $countParams['size'], $countParams['_source']);
        if (isset($countParams['body']['sort'])) {
            unset($countParams['body']['sort']);
        }
        $countRes = $client->count($countParams);
        $return = [
            'page' => $page,    //查询页码
            'pageSize' => $pageSize,    //查询每页返回数
            'totalPage' => 0,   //总分页数
            'total' => $countRes['count'] ?? 0,       //总记录数
            'records' => [],    //当前返回记录数
        ];
        $return['totalPage'] = ceil($return['total'] / $pageSize);
        if (isset($results['hits']) && !empty($results['hits'])) {
            foreach ($results['hits']['hits'] as $row) {
                $return['records'][] = array_merge(['_id' => $row['_id']], $row['_source']);
            }
        }
        return $return;
    }

    /**
     * 描述: scroll翻页搜索模式（适用于客户端滚动查询，执行简单，无法反映翻页期间数据的变动）
     * 使用返回的scrollId继续查询，当返回结果集数小于pageSize时，停止查询
     * 作者: Jayden
     */
    public static function esSearchScroll($node, $index, $queryBody, $sourceFields = true, $pageSize = 10, $page = 1, $sortArr = [], $scrollId = null)
    {
        $client = \Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
        $page = intval($page);
        $pageSize = intval($pageSize);
        $body = [];
        if (!empty($sortArr)) {
            $bodySort = self::bodySort($sortArr);
            if (!empty($bodySort)) {
                $body['sort'] = $bodySort;
            }
        }
        if (!empty($queryBody)) {
            $body['query'] = self::expandQuery($queryBody);
        }
        //需要考虑文档和搜索词的相关性（有分数），那么使用query; filter不需要计算相关性而且会缓存结果，有更好的性能
        //term不分词，match、match_phrase分词
        //keyword不分词，text分词
        $params = [
            'index' => $index,
            'body' => $body,
            '_source' => $sourceFields,
            'from' => ($page - 1) * $pageSize,
            'size' => $pageSize
        ];
        if (empty($scrollId)) {
            //10分钟, d,h,m,s
            $params['scroll'] = '10m';
            $results = $client->search($params);
            $countParams = $params;
            unset($countParams['from'], $countParams['size'], $countParams['_source']);
            if (isset($countParams['body']['sort'])) {
                unset($countParams['body']['sort']);
            }
            if (isset($countParams['scroll'])) {
                unset($countParams['scroll']);
            }
            $countRes = $client->count($countParams);
        } else {
            $params = [
                'body' => [
                    'scroll' => '10m',
                    'scroll_id' => $scrollId,
                ],
            ];
            $results = $client->scroll($params);
        }
        $scrollId = $results['_scroll_id'] ?? '';
        $return = [
            'page' => $page,    //查询页码
            'pageSize' => $pageSize,    //查询每页返回数
            'totalPage' => 0,   //总分页数
            'total' => $countRes['count'] ?? 0,       //总记录数
            'scrollId' => $scrollId ?? '',       //总记录数
            'records' => [],    //当前返回记录数
        ];
        $return['totalPage'] = ceil($return['total'] / $pageSize);
        if (isset($results['hits']) && !empty($results['hits'])) {
            foreach ($results['hits']['hits'] as $row) {
                $return['records'][] = array_merge(['_id' => $row['_id']], $row['_source']);
            }
        } else if (!empty($scrollId)) {
            $client->clearScroll([
                'body' => [
                    'scroll_id' => $scrollId
                ],
            ]);
        }
        return $return;
    }

    /**
     * 描述: search after翻页搜索模式（能实时查询、效率高，但操作麻烦，必须有排序字段，每次要传上次返回结果集的最后排序值）
     * 作者: Jayden
     */
    public static function esSearchAfter($node, $index, $queryBody, $sourceFields = true, $pageSize = 10, $page = 1, $sortArr = [])
    {
        $client = \Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
        $page = intval($page);
        $pageSize = intval($pageSize);
        $body = [];
        if (!empty($sortArr)) {
            $bodySort = self::bodySort($sortArr);
            if (!empty($bodySort)) {
                $body['sort'] = $bodySort;
            }
        } else {
            $body['sort']['_id'] = ['order' => 'asc'];
        }
        if (!empty($queryBody)) {
            $body['query'] = self::expandQuery($queryBody);
        }
        $results = [];
        $results['hits']['hits'] = [];
        $params = [
            'index' => $index,
            'body' => $body,
            '_source' => $sourceFields,
            'from' => ($page - 1) * $pageSize,
            'size' => $pageSize
        ];
        //todo 这里仅供服务端查询，执行返回所有数据；当使用客户端查询时，需传最后的id及pit值
        $pitResults = $client->openPointInTime(['index' => $index, 'keep_alive' => '2m']);
        $pitId = $pitResults['id'] ?? null;
        unset($params['index']);
        while (true) {
            if (empty($pitId)) {
                break;
            }
            $endItm = !empty($tempResults['hits']) ? end($tempResults['hits']['hits']) : [];
            $params['body']['pit'] = ['id' => $pitId, 'keep_alive' => '2m'];
            if (!empty($endItm)) {
                $params['body']['search_after'] = $endItm['sort'];
            }
            $tempResults = $client->search($params);
            $pitId = $tempResults['pit_id'] ?? null;
            if (empty($tempResults['hits']['hits'])) {
                break;
            }
            $results['hits']['hits'] = array_merge($results['hits']['hits'], $tempResults['hits']['hits']);
        }
        if (!empty($pitId)) {
            $client->closePointInTime(['body' => ['id' => $pitId]]);
        }
        $return = [
            'total' => 0,       //总记录数
            'records' => [],    //当前返回记录数
        ];
        if (isset($results['hits']) && !empty($results['hits'])) {
            foreach ($results['hits']['hits'] as $row) {
                $return['records'][] = array_merge(['_id' => $row['_id']], $row['_source']);
            }
        }
        $return['total'] = count($return['records']);
        return $return;
    }

    /**
     * 描述: 扩展查询
     * 作者: Jayden
     */
    protected static function expandQuery($queryBody)
    {
        $query = [];
        $must = $mustNot = $should = [];
        //比较符转换
        $keyMap = ['>' => 'gt', '<' => 'lt', '>=' => 'gte', '<=' => 'lte'];
        foreach ($queryBody as $name => $item) {
            if (!is_array($item)) {
                if (strpos($name, '!=') > 0 || strpos($name, '<>') > 0) {
                    //直接筛选，不等于
                    $name = trim(str_replace(['!=', '<>'], '', $name));
                    $mustNot[] = ['term' => [$name => $item]];
                } else {
                    //等于
                    $must[] = ['term' => [$name => $item]];
                }
            } else {
                $firstKey = key($item);
                if (in_array($firstKey, ['eq', '='])) {
                    $must[] = ['term' => [$name => $item[$firstKey]]];
                } else if ($firstKey == 'in') {
                    $must[] = ['terms' => [$name => $item[$firstKey]]];
                } else if ($firstKey == 'not_in') {
                    $mustNot[] = ['terms' => [$name => $item[$firstKey]]];
                } else if (in_array($firstKey, ['neq', '!=', '<>'])) {
                    $mustNot[] = ['term' => [$name => $item[$firstKey]]];
                } else if (in_array($firstKey, ['gt', 'lt', 'gte', 'lte', '>', '<', '>=', '<='])) {
                    $newItem = [];
                    foreach ($item as $itemName => $value) {
                        if (isset($keyMap[$itemName])) {
                            $itemName = $keyMap[$itemName];
                        }
                        if (in_array($itemName, ['gt', 'lt', 'gte', 'lte'])) {
                            //合法比较符才使用
                            $newItem[$itemName] = $value;
                        }
                    }
                    if (!empty($newItem)) {
                        $must[] = ['range' => [$name => $newItem]];
                    }
                } else if ($firstKey == 'like_keyword') {
                    $must[] = ['wildcard' => [$name => '*' . $item[$firstKey] . '*']];
                } else if ($firstKey == 'like_text') {
                    //字段设置为keyword类型，分词器ik，"analyzer": "ik_max_word",
                    //prefix|wildcard|regexp|fuzzy
                    if (is_array($item[$firstKey])) {
                        //指定搜索方式
                        if (isset($item[$firstKey][0], $item[$firstKey][1])) {
                            $must[] = ['prefix' => [$item[$firstKey][1] => $item[$firstKey][0]]];
                        }
                    } else {
                        //默认
                        $must[] = ['wildcard' => [$name => '*' . $item[$firstKey] . '*']];
                    }
                } else if ($firstKey == 'like') {
                    //使用通配符匹配，keyword,text都能匹配，但text已分词的不准
                    $must[] = ['wildcard' => [$name => '*' . $item[$firstKey] . '*']];
                } else if ($firstKey == 'not_like') {
                    //使用通配符匹配，wildcard，(mysql-> not like)
                    $mustNot[] = ['wildcard' => [$name => '*' . $item[$firstKey] . '*']];
                }
            }
        }
        if (!empty($must)) {
            $query['bool']['must'] = $must;
        }
        if (!empty($mustNot)) {
            $query['bool']['must_not'] = $mustNot;
        }
        if (!empty($should)) {
            $query['bool']['should'] = $should;
            $query['bool']['minimum_should_match'] = 1;
        }
        return $query;
    }

    /**
     * 描述 :
     * 作者 : Jayden
     */
    protected static function bodySort($sortArr)
    {
        $bodySort = [];
        if (is_string($sortArr)) {
            //$sortArr = 'createTime DESC, spu ASC'
            $tempSortArr = explode(',', $sortArr);
            $sortArr = [];
            foreach ($tempSortArr as $item) {
                $t = explode(' ', trim($item));
                if (!empty($t[0]) && !empty($t[1])) {
                    $t[0] = trim($t[0]);
                    $t[1] = trim($t[1]);
                    $sortArr[$t[0]] = $t[1];
                }
            }
        }
        foreach ($sortArr as $field => $sort) {
            $sort = strtolower($sort);
            $bodySort[$field] = ['order' => ($sort == 'desc') ? 'desc' : 'asc'];
        }
        return $bodySort;
    }

    /**
     * 描述: 查看信息
     * 作者: Jayden
     */
    public static function getInfo()
    {
        $node = of::config('env.esLog');
        $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
        $info = $client->info();
        echo json_encode($info);
    }

    /**
     * 描述 : 原始查找
     * 作者 : Jayden
     */
    public function searchRaw($node, $params)
    {
        $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
        $response = $client->search($params);
        return $response;
    }

    /**
     * 描述: 保存记录，_id存在则更新，不存在则新增(覆盖原有数据更新)
     * $data['_id'] : es主键，字段不插入文档
     * 作者: Jayden
     */
    public static function save($node, $index, $data)
    {
        $client = Elasticsearch\ClientBuilder::create()
            ->setHosts($node)
            ->build();
        //主键处理
        if (isset($data['_id'])) {
            $_id = !empty($data['_id']) ? $data['_id'] : md5(uniqid('', true) . mt_rand());
            unset($data['_id']);
        }
        if (empty($data['esAddTime'])) {
            $data['esAddTime'] = time();
        }
        $params = [
            'index' => $index,
            'body' => $data,
        ];
        if (!empty($_id)) {
            $params['id'] = $_id;
        }
        $result = $client->index($params);
        return $result['_id'] ?? null;
    }

    /**
     * 描述: 插入一条记录，存在则失败，
     * $data['_id'] : es主键，字段不插入文档
     * 作者: Jayden
     */
    public static function insertOne($node, $index, $data)
    {
        try {
            $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
            if (isset($data['_id'])) {
                $_id = !empty($data['_id']) ? $data['_id'] : md5(uniqid('', true) . mt_rand());
                unset($data['_id']);
            }
            if (empty($data['esAddTime'])) {
                $data['esAddTime'] = time();
            }
            $params = [
                'index' => $index,
                'body' => $data,
            ];
            if (!empty($_id)) {
                $params['id'] = $_id;
            }
            $result = $client->create($params);
            return $result['_id'] ?? null;
        } catch (\Exception $e) {
            self::$errorMessage = $e->getMessage();
            //记录日志
            of::event('of::error', true, [
                'memo' => true,
                'code' => E_USER_ERROR,
                'info' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return false;
        }
    }

    /**
     * 描述: 批量插入记录
     * 作者: Jayden
     */
    public static function insertMany($node, $index, $data)
    {
        //todo
        return true;
    }

    /**
     * 描述 : 删除文档，与查询文档类型 deleteByQuery
     * 作者 : Jayden
     */
    public static function deleteDocument()
    {
        $input = file_get_contents("php://input");
        $inputArr = json_decode($input, true);
        if (empty($inputArr['node']) || empty($inputArr['index']) || empty($inputArr['body'])) {
            return ['info' => 'index err'];
        }
        $node = of::config('env.' . $inputArr['node']);
        if (empty($node) || !is_array($node)) {
            return ['info' => 'node not found'];
        }
        $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
        $params = [
            'index' => $inputArr['index'],
            'body' => $inputArr['body'],
        ];
        $result = $client->deleteByQuery($params);
        return $result;
    }


    /**
     * 描述 : 新增索引
     * 作者 : Jayden
     * 访问方式 : {{host}}/demo/?c=jayden_esTool&a=createIndex
     */
    public static function createIndex()
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
        $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
        $inIndex = $client->indices()->exists(['index' => $inputArr['index']]);
        //索引不存在，创建索引
        if (empty($inIndex)) {
            $client->indices()->create(['index' => $inputArr['index'], //索引名称
                    'body' => [
                        'settings' => [ //配置
                            'number_of_shards' => $inputArr['number_of_shards'] ?? 2,//主分片数，注意：索引的主分片primary shards定义好后，后面不能做修改。
                            'number_of_replicas' => 1 //主分片的副本数
                        ],
                        'mappings' => [  //映射结构
                            'properties' => $inputArr['mappings']['properties'] ?? [],
                        ],
                    ]
                ]
            );
            return ['code' => 200, 'index' => $inputArr['index'], 'info' => '创建索引成功'];
        } else {
            return ['code' => 401, 'index' => $inputArr['index'], 'info' => '索引已存在'];
        }
    }

    /**
     * 描述 : 删除
     * 作者 : Jayden
     */
    public static function deleteIndex()
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
        $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
        $res = $client->indices()->delete(['index' => $inputArr['index']]);  //删除索引
        return ['index' => $inputArr['index'], 'info' => '删除索引成功', 'res' => $res];
    }

    /**
     * 描述 : getMapping
     * 作者 : Jayden
     */
    public static function getMapping($node, $indexName)
    {
        $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
        $res = $client->indices()->getMapping(['index' => $indexName]);
        return $res;
    }

    /**
     * 描述 : putMapping更新es索引
     * 作者 : Jayden
     */
    public static function putMapping($node, $indexName, $properties)
    {
        $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
        $params = [
            'index' => $indexName,
            'body' => [
                'properties' => $properties
            ],
        ];
        $res = $client->indices()->putMapping($params);
        return $res;
    }

    /**
     * 描述 : 创建索引周期
     * $policiesName = '7d-2d-2d-delete-new';
     * $dayConfig = [
     * 'hot' => 10,
     * 'cold' => 3,
     * 'delete' => 2,
     * ];
     * 作者 : Jayden
     */
    public static function createLifecyclePolicies($node, $policiesName, $dayConfig)
    {
        $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
        $params = [
            //索引生命周期的名字
            'policy' => $policiesName,
            'body' => [
                'policy' => [
                    'phases' => [
                        'hot' => [
                            "actions" => [
                                //滚动创建新索引的触发条件
                                "rollover" => [
                                    "max_age" => "{$dayConfig['hot']}d",
                                    //"max_size" => "500gb",
                                    "max_primary_shard_size" => "500gb",        //最大
                                ]
                            ]
                        ],
                        'cold' => [
                            "min_age" => "{$dayConfig['cold']}d",
                            "actions" => [
                                "set_priority" => [
                                    "priority" => 0
                                ],
                                //"readonly" => [],           //只读
                            ]
                        ],
                        'delete' => [
                            'min_age' => "{$dayConfig['delete']}d",
                            'actions' => [
                                'delete' => [],
                            ],
                        ],
                    ]
                ]
            ]
        ];
        $res = $client->ilm()->putLifecycle($params);
        return $res;
    }

    /**
     * 描述 : 创建索引模版
     * $policiesName 管理的索引生命周期策略
     * 作者 : Jayden
     */
    public static function createIndexTemplate($node, $policiesName, $indexName, $mappings)
    {
        $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
        $res = $client->indices()
            ->putIndexTemplate([
                'name' => $indexName,
                'body' => [
                    'index_patterns' => [
                        $indexName . "*"
                    ],
                    'data_stream' => new \stdClass(),
                    'template' => [
                        'settings' => [ //配置
                            "index.lifecycle.name" => $policiesName,  //索引生命周期，要先创建
                            "index.number_of_shards" => 12,
                            "index.number_of_replicas" => 0,
                            "index.translog.durability" => "async",
                            "index.translog.sync_interval" => "120s",
                            "index.translog.flush_threshold_size" => "1024mb",
                            "index.refresh_interval" => "30s"
                        ],
                        'mappings' => $mappings,
                    ],
                    'composed_of' => [],
                ]
            ]);
        return $res;
    }
}