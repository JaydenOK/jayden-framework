<?php

require '../src/RedisProxy.php';

$config = [
    'host' => '192.168.92.208',
    'port' => '7001',
    'password' => 'fok09213',
];
$redisClient = new RedisProxy($config['host'], $config['port'], $config['password']);
$return = $redisClient->set('foo.bar', "aaa123啧啧啧", 60);
$return = $redisClient->get('foo.bar');
print_r($return);