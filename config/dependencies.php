<?php

use app\service\di\CacheManager;
use DI\Container;

return [
    CacheManager::class => function () {
        return new CacheManager(new \app\service\di\Mailer());
    },
];
