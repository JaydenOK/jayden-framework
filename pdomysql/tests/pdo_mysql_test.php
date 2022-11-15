<?php


//http://jayden.cc/pdomysql/tests/pdo_mysql_test.php

//Add the following line in your composer.json file:
//"require": {
//    ...
//    "envms/fluentpdo": "^2.2.0"
//}
//update your dependencies with composer update, and you're done!


require '../bootstrap.php';

$fluent = (new module\tests\PdoClient())->getQuery();

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