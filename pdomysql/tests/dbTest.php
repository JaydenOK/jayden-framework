<?php


use module\lib\DB;

require '../bootstrap.php';

$config = [
    'host' => '192.168.1.222',
    'user' => 'root',
    'password' => 'root',
    'dbname' => 'test',
    'port' => '3306',
    'charset' => 'utf8',
];

$db = new DB($config);

$sql = "select * from `user` where uid=:uid";
$param = [
    'uid' => 3
];
$row = $db->fetchAll($sql, $param);

print_r($row);

