<?php

//引入vendor下的composer加载器
use app\core\lib\App;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/core/function/functions.php';
require __DIR__ . '/core/lib/App.php';

//开发环境还是生产环境
defined('DEBUG') || define('DEBUG', false);
//定义分隔符、应用目录、应用命名空间
defined('DS') || define('DS', DIRECTORY_SEPARATOR);
defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('APP_NAME') || define('APP_NAME', 'app');

$app = App::getInstance();
$app->run();



