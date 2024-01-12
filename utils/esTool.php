<?php
/**
 * Elasticsearch工具类
 * @author https://github.com/JaydenOK
 */

namespace serv\rapi;

use ctrl\system\esLog;
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
                        //date时间类型说明：
                        //ES底层存储的是Unix时间戳，并且录入的时间是有时区信息的，定义字段加了格式化没有时区时，按0时区保存；因此存储和查询都用定义的格式串是没有问题的
                        //如定义createTime字段date类型:{"createTime":{"type":"date","format":"yyyy-MM-dd HH:mm:ss"}},
                        //存储2024-01-08 12:00:00, 实际保存为:2024-01-08T12:00:00.000Z, 但查询可以用大于、等于2024-01-08 12:00:00查询到
                        //sql查询用2024-01-08 12:00:00，但其返回为2024-01-08T12:00:00.000Z格式
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
    public static function testGetInfo()
    {
        $node = of::config('env.esLog');
        $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
        $info = $client->info();
        header("content-type: application/json");
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
     * 描述: 保存记录，不存在则新增，存在_id则完全覆盖更新
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
    public static function insertOne($node, $index, $data, $replace = true)
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
     * replace 主键_id记录存在是否替换 ，true (index存在覆盖更新), false(create存在则插入失败)
     * （2）create：PUT /index/type/id/_create；只创建新文档
     * （3）index：普通的put操作，可以是创建文档，也可以是全量替换文档
     *  https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-bulk.html
     * 作者: Jayden
     */
    public static function insertMany($node, $index, $data, $replace = true)
    {
        try {
            if (!is_array($data)) {
                return false;
            }
            $insertData = [];
            $type = $replace ? 'index' : 'create';
            foreach ($data as $key => $item) {
                $_id = null;
                if (isset($item['_id'])) {
                    $_id = !empty($item['_id']) ? $item['_id'] : md5(uniqid('', true) . mt_rand());
                    unset($item['_id']);
                }
                $item['esAddTime'] = !empty($item['esAddTime']) ? $item['esAddTime'] : time();
                $meta = [];
                $meta[$type]['_index'] = $index;
                if (!empty($_id)) {
                    $meta[$type]['_id'] = $_id;
                }
                $insertData[] = $meta;
                $insertData[] = $item;
            }
            $params = [
                'index' => $index,  //默认索引，可在里层传
                'body' => $insertData,
            ];
            $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
            $result = $client->bulk($params);
            return $result ?? null;
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
     * 描述: 查找一个记录
     * {"_id": "t1","imsUUID":"123"}
     * 作者: Jayden
     */
    public static function findOne($node, $index, $queryBody, $sourceFields = true)
    {
        if (empty($queryBody)) {
            return false;
        }
        try {
            $client = Elasticsearch\ClientBuilder::create()
                ->setHosts($node)
                ->build();
            $body = [];
            $body['query'] = self::expandQuery($queryBody);
            $pageSize = 1;
            $page = 1;
            if (!empty($queryBody['_id'])) {
                //有主键查询，get()只能根据主键id查
                $params = ['id' => $queryBody['_id'], 'index' => $index];
                if (!empty($sourceFields)) {
                    $params['_source'] = $sourceFields;
                }
                $tempResults = $client->get($params);
                if (!empty($tempResults['found']) && $tempResults['found'] === true) {
                    $results = [];
                    $results['hits']['total']['value'] = 1;
                    $results['hits']['hits'] = [$tempResults];
                }
            } else {
                //无主键查询
                $params = [
                    'index' => $index,
                    'body' => $body,
                    '_source' => $sourceFields,
                    'from' => ($page - 1) * $pageSize,
                    'size' => $pageSize
                ];
                $results = $client->search($params);
            }
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
        } catch (\Exception $e) {
            self::$errorMessage = $e->getMessage();
            $errorMessage = json_decode(self::$errorMessage, true);
            if (isset($errorMessage['found']) && $errorMessage['found'] === false) {
                //未找到记录返回
                return [
                    'total' => 0,
                    'records' => [],
                ];
            }
            return false;
        }
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
     * 描述: 批量更新，根据id批量更新
     * 作者: Jayden
     */
    public static function updateMany($node, $index, $data)
    {
        try {
            if (!is_array($data)) {
                return false;
            }
            $updateData = [];
            foreach ($data as $key => $item) {
                if (empty($item['_id'])) {
                    throw new \Exception("empty _id, index:{$key}");
                }
                $meta = [];
                $meta['update']['_index'] = $index;
                $meta['update']['_id'] = $item['_id'];
                unset($item['_id']);
                $updateData[] = $meta;
                $updateData[] = ['doc' => $item];
            }
            $params = [
                'index' => $index,  //默认索引
                'body' => $updateData,
            ];
            $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
            $result = $client->bulk($params);
            return $result ?? null;
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
     * 描述 : 删除文档，指定索引名称和文档_id
     * 对文档执行的每个写入操作（包括删除）都会导致其版本增加。已删除文档的版本号在删除后仍会在短时间内保留，以便控制并发操作。
     * 已删除文档的版本保持可用的时间长度由index.gc_deletes索引设置确定，默认为 60 秒。
     * $refresh = false(默认，更改短时间后可见), true（使更改立即刷新生效）,  wait_for (等待请求所做的更改通过刷新可见，然后再回复)
     * 作者 : Jayden
     */
    public static function delete($node, $index, $_id, $refresh = false)
    {
        try {
            $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
            $params = [
                'index' => $index,
                'id' => $_id,
            ];
            if (!empty($refresh)) {
                $params['refresh'] = $refresh;
            }
            $result = $client->delete($params);
            return isset($result['result']) && $result['result'] == 'deleted';
        } catch (\Exception $e) {
            self::$errorMessage = $e->getMessage();
            $errorMessage = json_decode(self::$errorMessage, true);
            if (isset($errorMessage['result']) && $errorMessage['result'] === 'not_found') {
                //不存在记录返回
                return true;
            }
            return false;
        }
    }

    /**
     * 描述 : 删除与指定查询匹配的文档（与查询文档类似条件）
     *
     * 当您提交按查询删除请求时，Elasticsearch 会在开始处理请求时获取数据流或索引的快照，并使用 internal版本控制删除匹配的文档。
     * 如果在拍摄快照和处理删除操作之间文档发生更改，则会导致版本冲突并且删除操作失败。
     * 版本等于 0 的文档无法使用按查询删除来删除，因为internal版本控制不支持 0 作为有效版本号。
     * 在处理按查询删除请求时，Elasticsearch 会顺序执行多个搜索请求以查找要删除的所有匹配文档。对每批匹配的文档执行批量删除请求。
     * 如果搜索或批量请求被拒绝，请求最多会重试 10 次，并呈指数回退。如果达到最大重试限制，处理将停止并在响应中返回所有失败的请求。任何成功完成的删除请求仍然保留，不会回滚。
     * 您可以选择对版本冲突进行计数，而不是通过设置为conflicts来停止并返回proceed。
     * 请注意，如果您选择计算版本冲突，则操作可能会尝试从源中删除更多文档，max_docs直到成功删除max_docs文档或遍历源查询中的每个文档为止
     *
     * conflicts=proceed 可选，版本冲突是否继续执行，默认取消abort (abort,proceed)
     * wait_for_completion=false 可选，通过查询异步运行删除
     * max_docs 可选，要处理的最大文档数。默认为所有文档
     * 作者 : Jayden
     */
    public static function deleteByQuery($node, $index, $queryBody, $extraParams = [])
    {
        $body = [];
        if (!empty($queryBody)) {
            $body['query'] = self::expandQuery($queryBody);
        }
        if (empty($body)) {
            return false;
        }
        $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
        $params = [
            'index' => $index,
            'body' => $body,
        ];
        if (!empty($extraParams)) {
            $params = array_merge($extraParams, $params);
        }
        $result = $client->deleteByQuery($params);
        //$client->indices()->forcemerge();       //执行forceMerge合并，手动释放磁盘空间
        //return $result['deleted'] ?? 0;
        return $result['total'] ?? 0;
    }

    /**
     * 描述 : 新增索引
     * 作者 : Jayden
     * 访问方式 : {{host}}/demo/?c=jayden_esTool&a=createIndex
     */
    public static function createIndex($node, $index, $mappings, $numberOfShards, $numberOfReplicas = 1)
    {
        $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
        $exists = $client->indices()->exists(['index' => $index]);
        if (!empty($exists)) {
            return false;
        }
        //索引不存在，创建索引
        $params = [
            'index' => $index,
            'body' => [
                'settings' => [
                    'number_of_shards' => $numberOfShards ?? 4,     //主分片数
                    'number_of_replicas' => $numberOfReplicas ?? 1,   //每个主分片的副本数
                ],
                'mappings' => [
                    'properties' => $mappings['properties'] ?? [],
                ],
            ]
        ];
        $res = $client->indices()->create($params);
        return $res;
    }

    /**
     * 描述 : 删除索引
     * 作者 : Jayden
     */
    public static function deleteIndex($node, $index)
    {
        $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
        $res = $client->indices()->delete(['index' => $index]);
        return $res;
    }

    /**
     * 描述 : getMapping
     * 作者 : Jayden
     */
    public static function getMapping($node, $index)
    {
        $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
        $res = $client->indices()->getMapping(['index' => $index]);
        return $res;
    }

    /**
     * 描述 : putMapping更新es索引
     * 作者 : Jayden
     */
    public static function putMapping($node, $index, $properties)
    {
        $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
        $params = [
            'index' => $index,
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
     * 'hot' => '30d',
     * 'warm' => '40d',
     * 'cold' => '50d',
     * 'delete' => '60d',
     * ];
     * 作者 : Jayden
     */
    public static function createLifecyclePolicies($node, $policiesName, $dayConfig)
    {
        $params = [
            'policy' => $policiesName,  //索引生命周期的名称
            'body' => [
                'policy' => [
                    'phases' => [
                        'hot' => [
                            "min_age" => "0ms",     //如果创建的策略Policy 具有未指定 min_age 的热阶段，min_age 默认为 0 ms。
                            "actions" => [
                                //滚动创建新索引的触发条件
                                "rollover" => [
                                    "max_age" => "{$dayConfig['hot']}",       //历史索引保留时间的判断条件
                                    //"max_size" => "500gb",                    //当索引达到一定大小时触发翻转。这是索引中所有主分片的总大小。副本不计入最大索引大小。
                                    "max_primary_shard_size" => "500gb",        //防止滚动，直到索引中最大的主分片达到一定大小
                                ],
                                "set_priority" => [
                                    "priority" => 100
                                ],
                            ]
                        ],
                        'warm' => [
                            "min_age" => "{$dayConfig['warm']}",   //min_age通常是指从索引被创建时算起的时间，多少时间后进入此阶段，设置索引进入warm阶段所需的时间。
                            "actions" => [
                                "set_priority" => [
                                    "priority" => 50
                                ],
                            ]
                        ],
                        'cold' => [
                            "min_age" => "{$dayConfig['cold']}",
                            "actions" => [
                                "set_priority" => [
                                    "priority" => 0
                                ],
                                "readonly" => new \stdClass(),           //只读
                            ]
                        ],
                        'delete' => [
                            'min_age' => "{$dayConfig['delete']}",
                            'actions' => [
                                'delete' => new \stdClass(),
                            ],
                        ],
                    ]
                ]
            ]
        ];
        $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
        $res = $client->ilm()->putLifecycle($params);
        return $res;
    }

    /**
     * 描述 : 创建索引模版
     * $policiesName 管理的索引生命周期策略
     * 使用data_stream管理索引模板，模板$mappings需增加字段: "@timestamp": {"type": "date","format": "yyyy-MM-dd HH:mm:ss"}
     * 作者 : Jayden
     */
    public static function createIndexTemplate($node, $policiesName, $index, $mappings)
    {
        $params = [
            'name' => $index,
            'body' => [
                'index_patterns' => [
                    $index . "*"
                ],
                'data_stream' => new \stdClass(),   //索引到数据流的每个文档都必须包含一个@timestamp映射为date或date_nanos字段类型的字段。如果索引模板未指定字段的映射@timestamp，Elasticsearch 会映射 @timestamp为date具有默认选项的字段。
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
        ];
        $client = Elasticsearch\ClientBuilder::create()->setHosts($node)->build();
        $res = $client->indices()->putIndexTemplate($params);
        return $res;
    }
}