<?php

use ArrayTree\Tree;

//加载命名空间
include_once(__DIR__ . '/bootstrap.php');

$dataList = array(
    0 => array('catid' => '4', 'name' => '某某有限公司', 'pid' => '0', 'sort' => '1',),
    1 => array('catid' => '12334', 'name' => '客户运营部', 'pid' => '4', 'sort' => '100006500',),
    3 => array('catid' => '92937', 'name' => '市场部', 'pid' => '4', 'sort' => '100006875',),
    4 => array('catid' => '92938', 'name' => '研发中心', 'pid' => '4', 'sort' => '100006906',),
    5 => array('catid' => '92973', 'name' => '机械部', 'pid' => '92938', 'sort' => '100004000',),
    6 => array('catid' => '92974', 'name' => '中心部', 'pid' => '92938', 'sort' => '100002999',),
    7 => array('catid' => '92978', 'name' => '财务部', 'pid' => '4', 'sort' => '100006937',),
    9 => array('catid' => '92985', 'name' => '人事行政部', 'pid' => '4', 'sort' => '100006687',),
    13 => array('catid' => '93595', 'name' => '开发一组', 'pid' => '92938', 'sort' => '100006000',),
    14 => array('catid' => '93596', 'name' => '开发二组', 'pid' => '92938', 'sort' => '100005500',),
    15 => array('catid' => '98560', 'name' => '开发三组', 'pid' => '92938', 'sort' => '100005000',),
    18 => array('catid' => '92999', 'name' => '中心部-子部', 'pid' => '92974', 'sort' => '100002999',),
    19 => array('catid' => '1111111', 'name' => '中心部-子部2', 'pid' => '92974', 'sort' => '100',),
    20 => array('catid' => '2222222', 'name' => '开发一组子分类', 'pid' => '93595', 'sort' => '100',),
    21 => array('catid' => '3333333', 'name' => '中心部-子部2-子部33', 'pid' => '1111111', 'sort' => '100',),
);

$dataTree = new Tree($dataList);
//设置主键、父键、及根ID()
$dataTree->setIdKey('catid')->setParentIdKey('pid')->setRootId($dataList[0]['catid']);

try {
    /**
     * 例1，获取数组树
     * 用途：前端部门架构显示
     */
    $dataTreeList = $dataTree->getArrayTree();
    echo json_encode($dataTreeList, JSON_UNESCAPED_UNICODE);

    echo "\n\n#############################################\n\n";
    /**
     * [
     * {
     * "catid":"12334",
     * "name":"客户运营部",
     * "pid":"4",
     * "sort":"100006500",
     * "parent_ids":[
     *
     * ],
     * "childs":[
     *
     * ]
     * },
     * {
     * "catid":"92937",
     * "name":"市场部",
     * "pid":"4",
     * "sort":"100006875",
     * "parent_ids":[
     *
     * ],
     * "childs":[
     *
     * ]
     * },
     * {
     * "catid":"92938",
     * "name":"研发中心",
     * "pid":"4",
     * "sort":"100006906",
     * "parent_ids":[
     *
     * ],
     * "childs":[
     * {
     * "catid":"92973",
     * "name":"机械部",
     * "pid":"92938",
     * "sort":"100004000",
     * "parent_ids":[
     * 92938
     * ],
     * "childs":[
     *
     * ]
     * },
     * {
     * "catid":"92974",
     * "name":"中心部",
     * "pid":"92938",
     * "sort":"100002999",
     * "parent_ids":[
     * 92938
     * ],
     * "childs":[
     * {
     * "catid":"92999",
     * "name":"中心部-子部",
     * "pid":"92974",
     * "sort":"100002999",
     * "parent_ids":[
     * 92974,
     * 92938
     * ],
     * "childs":[
     *
     * ]
     * },
     * {
     * "catid":"1111111",
     * "name":"中心部-子部2",
     * "pid":"92974",
     * "sort":"100",
     * "parent_ids":[
     * 92974,
     * 92938
     * ],
     * "childs":[
     * {
     * "catid":"3333333",
     * "name":"中心部-子部2-子部33",
     * "pid":"1111111",
     * "sort":"100",
     * "parent_ids":[
     * 1111111,
     * 92974,
     * 92938
     * ],
     * "childs":[
     *
     * ]
     * }
     * ]
     * }
     * ]
     * },
     * {
     * "catid":"93595",
     * "name":"开发一组",
     * "pid":"92938",
     * "sort":"100006000",
     * "parent_ids":[
     * 92938
     * ],
     * "childs":[
     * {
     * "catid":"2222222",
     * "name":"开发一组子分类",
     * "pid":"93595",
     * "sort":"100",
     * "parent_ids":[
     * 93595,
     * 92938
     * ],
     * "childs":[
     *
     * ]
     * }
     * ]
     * },
     * {
     * "catid":"93596",
     * "name":"开发二组",
     * "pid":"92938",
     * "sort":"100005500",
     * "parent_ids":[
     * 92938
     * ],
     * "childs":[
     *
     * ]
     * },
     * {
     * "catid":"98560",
     * "name":"开发三组",
     * "pid":"92938",
     * "sort":"100005000",
     * "parent_ids":[
     * 92938
     * ],
     * "childs":[
     *
     * ]
     * }
     * ]
     * },
     * {
     * "catid":"92978",
     * "name":"财务部",
     * "pid":"4",
     * "sort":"100006937",
     * "parent_ids":[
     *
     * ],
     * "childs":[
     *
     * ]
     * },
     * {
     * "catid":"92985",
     * "name":"人事行政部",
     * "pid":"4",
     * "sort":"100006687",
     * "parent_ids":[
     *
     * ],
     * "childs":[
     *
     * ]
     * }
     * ]
     */

    /**
     * 例2，获取指定ID节点的子节点数组
     * 用途：获取某个上级管理下的所有下级部门id，进而查找其权限
     */
    $id = 92974;
    $dataTreeList = $dataTree->getChildNodeDataArray($id, true, true);
    echo json_encode($dataTreeList, JSON_UNESCAPED_UNICODE);
    /*
    [
        {
            "catid":"92974",
            "name":"中心部",
            "pid":"92938",
            "sort":"100002999",
            "parent_ids":[
                92938
            ]
        },
        {
            "catid":"92999",
            "name":"中心部-子部",
            "pid":"92974",
            "sort":"100002999",
            "parent_ids":[
                92974,
                92938
            ]
        },
        {
            "catid":"1111111",
            "name":"中心部-子部2",
            "pid":"92974",
            "sort":"100",
            "parent_ids":[
                92974,
                92938
            ]
        },
        {
            "catid":"3333333",
            "name":"中心部-子部2-子部33",
            "pid":"1111111",
            "sort":"100",
            "parent_ids":[
                1111111,
                92974,
                92938
            ]
        }
    ]
     */

} catch (Exception $e) {
    print_r($e);
}