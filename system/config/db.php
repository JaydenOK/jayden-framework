<?php

/**
 * 数据库配置文件 - 生产环境
 * 
 * 环境选择：在项目入口文件定义常量 APP_ENV
 * - 'prod' 或 'production' 加载 db.php（生产环境）
 * - 'dev' 或 'development' 加载 db-dev.php（开发环境）
 * - 未定义时默认为生产环境
 * 
 * 示例：define('APP_ENV', 'dev');
 */

return [
    //default 虚拟主机池
    'default' => [
        'host' => 'xxx.com',
        'port' => 3306,
        'user' => 'xx',
        'password' => 'xxx',
        'database' => 'xxx',
        'charset' => 'utf8mb4'
    ],
    //iscs 虚拟主机池
    'iscs' => [
        'host' => 'xxx',
        'port' => 3306,
        'user' => 'iscs',
        'password' => 'xxx',
        'database' => 'xxx',
        'charset' => 'utf8mb4'
    ],
];
