<?php
/**
 * 引导程序文件
 */

define('PROJECT_DIR', dirname(__FILE__));
defined('DS') || define('DS', DIRECTORY_SEPARATOR);

require PROJECT_DIR . DS . 'autoloader' . DS . 'Autoloader.class.php';

//注册命名空间
$autoloader = Autoloader::GetInstance();
$autoloader->addNamespace('ArrayTree', PROJECT_DIR . DS . 'ArrayTree');
$autoloader->register();