<?php

/**
 * 消息队列配置文件
 *
 * 配置说明：
 * - cNum: 消费者进程数量（默认1）
 * - call: 消费者回调方法（支持 类名::方法名 格式）
 *
 * 动态配置：
 * - 修改此文件后，主进程会自动检测变化并重新加载
 * - 增加cNum：自动启动新消费者
 * - 减少cNum：优雅停止多余消费者（等待当前任务完成）
 * - 删除队列：停止该队列所有消费者
 * - 新增队列：自动启动消费者
 *
 * 使用方法：
 * 1. 启动主进程: php index.php system/mq/start
 * 2. 发送消息: MqManager::set('testMq1', $data)
 * 3. 查看状态: php index.php system/mq/status
 * 4. 停止主进程: php index.php system/mq/stop
 */

return [
    //default 虚拟主机池
    'default' => [
        //testMq1队列名称
        'testMq1' => [
            // 启动消费者进程数量
            'cNum' => 2,
            // 队列消费方法（接收参数为消息数组，包含 id, data, time 等字段）
            'call' => 'app\system\TestController::testMq1'
        ],
        'checkUser' => [
            // 启动消费者进程数量
            'cNum' => 1,
            // 队列消费方法（接收参数为消息数组，包含 id, data, time 等字段）
            'call' => 'app\system\TestController::checkUser'
        ],
    ],
    //iscs 虚拟主机池
    'iscs' => [
        'testMq2' => [
            // 启动消费者进程数量
            'cNum' => 3,
            // 队列消费方法（接收参数为消息数组，包含 id, data, time 等字段）
            'call' => 'app\system\TestController::testMq2'
        ],
    ],

];
