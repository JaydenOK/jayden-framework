<?php

//http://jayden.cc/pdomysql/tests/pdo_mysql_test.php

//Add the following line in your composer.json file:
//"require": {
//    ...
//    "envms/fluentpdo": "^2.2.0"
//}
//update your dependencies with composer update, and you're done!

require '../bootstrap.php';

$config = [
    'host' => '192.168.92.209',
    'user' => 'xxx',
    'password' => 'yibai#2022',
    'dbname' => 'yibai_account_system',
    'port' => '3306',
    'charset' => 'utf8',
];

$pdo = new PDO("mysql:dbname={$config['dbname']};host={$config['host']};charset={$config['charset']}", "{$config['user']}", "{$config['password']}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
$pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
$fluent = new module\FluentPDO\Query($pdo);


//查询
$query = $fluent->from('yibai_async_config')->where('id', 9)->fetch();
$query2 = $fluent->from('yibai_async_config')->where('exchange', 'async-message-exchange')->fetchAll();
print_r($query);

//新增
//$values = array('title' => 'article 1', 'content' => 'content 1');
//
//$query = $fluent->insertInto('article')->values($values)->execute();
//$query = $fluent->insertInto('article', $values)->execute(); // shorter version


//更新
$set = array('update_time' => date('Y-m-d H:i:s'));
$query = $fluent->update('yibai_async_config')->set($set)->where('id', 9)->execute();

//删除
//$query = $fluent->deleteFrom('article')->where('id', 1)->execute();

//Note: INSERT, UPDATE and DELETE queries will only run after you call ->execute()