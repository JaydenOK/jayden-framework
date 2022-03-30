<?php
/**
 * 第三方插件，命名空间引导程序文件，添加当前模块的顶级命名空间
 * 使用时，单独引入此文件即可: require_once THIRD_PARTY_DIR . '/swoole_multi_consumer_bootstrap.php';
 */

require_once APP_ROOT . '/autoloader/Autoloader.php';

$topNamespace = 'Pupilcp';
$sourceDir = THIRD_PARTY_DIR . '/swoole-multi-consumer/src';

$autoloader = Autoloader::getInstance();
$autoloader->addNamespace($topNamespace, $sourceDir);
$autoloader->register();