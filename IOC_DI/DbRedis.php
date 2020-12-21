<?php

namespace module;
use module\interfaces\IConfig;
use module\interfaces\IRedis;

class DbRedis implements IRedis
{
    public function __construct(IConfig $config)
    {
        $this->config = $config->getConfig();
        // do something
    }

    public function set()
    {
        echo __METHOD__ . PHP_EOL;
    }
}