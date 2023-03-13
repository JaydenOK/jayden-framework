<?php

// 原数组格式
$dataList = array(
    15 => array('catid' => '98560', 'name' => '开发三组', 'pid' => '92938', 'sort' => '100005000',),
    18 => array('catid' => '92999', 'name' => '中心部-子部', 'pid' => '92974', 'sort' => '100002999',),
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
    19 => array('catid' => '1111111', 'name' => '中心部-子部2', 'pid' => '92974', 'sort' => '100',),
    20 => array('catid' => '2222222', 'name' => '开发一组子分类', 'pid' => '93595', 'sort' => '100',),
    21 => array('catid' => '3333333', 'name' => '中心部-子部2-子部33', 'pid' => '1111111', 'sort' => '100',),
);


function arrayToTree($list, $pk = 'id', $pid = 'pid', $child = 'child', $root = 0)
{
    $tree = [];
    foreach ($list as $item) {
        if ($item[$pk] == $root) {
            $tree = $item;
        } else {
            $temp[$item[$pk]] = $item;
        }
    }
}