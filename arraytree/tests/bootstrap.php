<?php
/**
 * 通过自动加载类，注册命名空间
 */

defined('DS') || define('DS', DIRECTORY_SEPARATOR);
//arraytree
define('ROOT', dirname(__DIR__));

require ROOT . DS . 'vendor' . DS . 'Autoloader.class.php';

$autoloader = Autoloader::GetInstance();
//添加命名空间
$autoloader->addNamespace('ArrayTree', ROOT . DS . 'ArrayTree');
$autoloader->register();
