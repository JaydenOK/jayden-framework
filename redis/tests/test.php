<?php

require '../src/RedisClient.php';
$config = [
    'host' => '192.168.92.208',
    'port' => '7001',
    'password' => 'fok09213',
];
$redisClient = new RedisClient($config['host'], $config['port'], $config['password']);
$return = $redisClient->set('foo.bar', 3235);
$return = $redisClient->get('foo.bar');
print_r($return);