<?php

//引入vendor下的composer加载器
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/core/function/functions.php';
require __DIR__ . '/core/lib/Application.php';

(new Application())->run();



