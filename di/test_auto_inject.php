<?php

use module\cache\File;
use module\Container;
use module\cache\Redis;
use module\cache\Mongo;

require "bootstrap.php";

$container = new Container();

$redis = $container->get(Redis::class);
echo $redis->find('user'); // 获取缓存数据

$mongo = $container->get(Mongo::class);
echo $mongo->find('user'); // 获取缓存数据

$file = $container->get(File::class);
echo $file->find('user'); // 获取缓存数据
