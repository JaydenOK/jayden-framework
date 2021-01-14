<?php

use module\App;
use module\Cache;

require "bootstrap.php";

$app = new App();
//注入类
$app->inject();
$di = $app->getDi();
//需要使用DI注入类的代码上
/**
 * @var $cache Cache
 */
$cache = $di->get('redis');
echo $cache->get('user'); // 获取缓存数据
echo $cache->save('user', ['id' => 123, 'name' => '李明'], 30);
echo $cache->delete('user'); // 删除数据

//换缓存
$cache = $di->get('mongo');
echo $cache->get('user'); // 获取缓存数据
echo $cache->save('user', ['id' => 123, 'name' => '李明'], 30);
echo $cache->delete('user'); // 删除数据

//换缓存
$cache = $di->get('file');
echo $cache->get('user'); // 获取缓存数据
echo $cache->save('user', ['id' => 123, 'name' => '李明'], 30);
echo $cache->delete('user'); // 删除数据