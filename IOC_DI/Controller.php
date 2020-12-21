<?php

namespace module;

use module\interfaces\IMysql;
use module\interfaces\IRedis;

class Controller
{
    public $mysql;
    public $redis;

    public function __construct(IMysql $mysql, IRedis $redis)
    {
        $this->mysql = $mysql;
        $this->redis = $redis;
    }

    public function action()
    {
        is_object($this->mysql) && $this->mysql->query();
        is_object($this->redis) && $this->redis->set();
    }
}