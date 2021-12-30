<?php
/**
 * Swoole Rsync Task Demo
 * Swoole异步任务客户端，由定时任务去触发
 */

$host = '127.0.0.1';
$port = 12002;
$client = new Swoole\Client(SWOOLE_SOCK_TCP);
if (!$client->connect($host, $port, -1)) {
    exit("connect failed. Error: {$client->errCode}\n");
}
$platform = 'Amazon';
$client->send($platform);
//echo $client->recv();
$client->close();