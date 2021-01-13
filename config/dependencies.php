<?php

use app\service\di\UserManager;
use DI\Container;

return [
    UserManager::class => function () {
        return new UserManager(new \app\service\di\Mailer());
    },
];
