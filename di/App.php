<?php

namespace module;

use module\cache\File;
use module\cache\Mongo;
use module\cache\Redis;

class App
{

    protected $di;

    public function __construct()
    {
        $this->di = new Di();
    }

    public function getDi()
    {
        return $this->di;
    }

    public function inject()
    {
        $this->di->set('redisCache', function () {
            return new Redis();
        });
        $this->di->set('mongoCache', function () {
            return new Mongo();
        });
        $this->di->set('fileCache', function () {
            return new File();
        });

        $this->di->set('redis', function () {
            return new Cache(['type' => 'redisCache']);
        });
        $this->di->set('mongo', function () {
            return new Cache(['type' => 'mongoCache']);
        });
        $this->di->set('file', function () {
            return new Cache(['type' => 'fileCache']);
        });
    }
}