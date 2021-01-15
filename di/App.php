<?php

namespace module;

use module\cache\File;
use module\cache\Mongo;
use module\cache\Redis;

class App
{

    protected $container;

    public function __construct()
    {
        $this->container = new Container();
    }

    public function getContainer()
    {
        return $this->container;
    }

    public function inject()
    {
        $this->container->set('redisCache', function () {
            return new Redis();
        });
        $this->container->set('mongoCache', function () {
            return new Mongo();
        });
        $this->container->set('fileCache', function () {
            return new File();
        });

        $this->container->set('redis', function () {
            return new Cache(['type' => 'redisCache']);
        });
        $this->container->set('mongo', function () {
            return new Cache(['type' => 'mongoCache']);
        });
        $this->container->set('file', function () {
            return new Cache(['type' => 'fileCache']);
        });
    }
}