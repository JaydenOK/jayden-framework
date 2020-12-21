<?php

use module\Container;

require 'bootstrap.php';

// 类存在创建类实例:$param->getClass()->name => 'module\interfaces\IMysql';
// 命名空间有问题，没有命名空间，绑定的实例化对象名称，刚好是接口的名称？？

$app = new Container();
$app->bind('MConfig', 'module\MysqlConfig');
$app->bind('RConfig', 'module\RedisConfig');
$app->bind('SMysql', 'module\DbMysql');
$app->bind('SRedis', 'module\DbRedis');
$app->bind('controller', 'module\Controller');
$controller = $app->make('controller');
//$controller->action();

/**
 * 输出：
 * DbMysql::query
 * DbRedis::set
 */