<?php

require '../src/MysqliDb.php';

$hostname = '192.168.92.209';
$username = 'xxx';
$password = 'yibai#2022';
$database = 'yibai_account_manage';
$port = 3306;
$charset = 'utf8';

$db = new MysqliDb ([
    'host' => $hostname,
    'username' => $username,
    'password' => $password,
    'db' => $database,
    'port' => $port,
    //'prefix' => 'my_',
    'charset' => $charset,
]);

$tableName = 'yibai_platform_account_log';

//======  新增
//$data = ["platform_code" => "Amazon", "account_id" => 0, 'detail' => mt_rand(1000, 9999)];
//$id = $db->insert($tableName, $data);

//======  批量新增
//$ids = $db->insertMulti('users', $dataArr);

//======  更新
//$data = [
//    'platform_code' => 'Ebay',
//    'account_id' => 0,
//    'params' => json_encode(['login_name' => mt_rand(10000, 99999)]),
//    'detail' => '',
//];
//$res = $db->where('id', 1155)->update($tableName, $data);
//if ($res) {
//    echo $db->count . ' success update';
//} else {
//    echo 'update failed: ' . $db->getLastError();
//}


//======  查询
$columns = ["id", "platform_code", "account_id", 'detail', 'create_time'];
$logs = $db->where('id', 100, '>')->limit(20)->get($tableName, null, $columns);      //查所有
print_r($log);

//$page = 1;
//$pageLimit = 5;
//$logs = $db->where('id', 100, '>')->limit($pageLimit)->orderBy('id', 'desc')->groupBy('account_id')->paginate($tableName, $page);      //分页
//print_r($logs);

//$log = $db->getOne($tableName, "count(*) as total");      //查一个
//$column = $db->getValue($tableName, "detail");      //指定返回列

//echo $db->getLastQuery();       //最后查询sql


//=====  连接查询 join
//$db->join("users u", "p.tenantID=u.tenantID", "LEFT");
//$db->where("u.id", 6);
//$products = $db->get("products p", null, "u.name, p.productName");
//print_r($products);


//=====  多重数据库连接，默认new的连接名为: default
//$db->addConnection('slave',
//    [
//        'host' => $hostname,
//        'username' => $username,
//        'password' => $password,
//        'db' => $database,
//        'port' => $port,
//        //'prefix' => 'my_',
//        'charset' => 'utf8'
//    ]
//);
//选择数据库连接并执行查询
//$users = $db->connection('slave')->get($tableName);
