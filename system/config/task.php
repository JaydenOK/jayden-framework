<?php

return [
    'testTask1' => [
        //遵循linux crontab 格式
        'time' => '*/15 * * * *',
        //回调方法,返回 false 认为失败
        'call' => 'app\system\TestController::testTask1',
        //并发执行1个，即启动2个消费者进程
        'cNum' => 1,
        //第一次失败 60秒后重试 120秒后重试 300s后重试
        'try' => [60, 120, 300],
    ],
];
