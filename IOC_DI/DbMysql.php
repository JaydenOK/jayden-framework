<?php

namespace module;

use module\interfaces\IConfig;
use module\interfaces\IMysql;

class DbMysql implements IMysql
{

    public $config;

    public function __construct(IConfig $config)
    {
        $this->config = $config->getConfig();
        // do something
    }

    public function query()
    {
        echo __METHOD__ . PHP_EOL;
    }
}