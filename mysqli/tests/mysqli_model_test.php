<?php

require '../src/MysqliDb.php';
require '../src/dbObject.php';
require '../models/LogModel.php';

$hostname = '192.168.92.209';
$username = 'xxx';
$password = 'yibai#2022';
$database = 'yibai_account_manage';
$port = 3306;
$charset = 'utf8';

//实例化默认default: $_instance
$db = new Mysqlidb($hostname, $username, $password, $database, $port, $charset);

$log = new LogModel();
$log->platform_code = 'Ebay';
$log->account_id = 0;
$log->detail = 'aaa';
$id = $log->save();
if ($id) {
    echo "user created with id = " . $id;
} else {
    echo 'error';
}


//$logs = LogModel::where('id', 1000, '>')->get();
//print_r($logs);


