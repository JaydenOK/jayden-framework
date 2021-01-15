<?php

use module\cache\File;
use module\cache\Redis;
use module\cache\Mongo;

return [
    Redis::class => function () {
        return new Redis();
    },
    Mongo::class => Mongo::class,
    File::class => new File(),
];
