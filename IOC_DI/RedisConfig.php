<?php

namespace module;

use module\interfaces\IRedis;

class RedisConfig implements IRedis
{
    public function getConfig()
    {
        // 获取配置
        return ['host', 'name', 'pwd'];
    }

    public function Set()
    {
        // TODO: Implement Set() method.
    }
}