<?php


use lib\DB;

require '../bootstrap.php';

$db = new DB();


$sql = "select * from `user` where uid=:uid";
$param = [
    'uid' => 3
];
$row = $db->fetchAll($sql, $param);

print_r($row);

