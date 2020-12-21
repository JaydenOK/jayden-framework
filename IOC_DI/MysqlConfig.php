<?php

namespace module;
use module\interfaces\IConfig;

class MysqlConfig implements IConfig
{
    public function getConfig()
    {
        // 获取配置
        return ['host', 'name', 'pwd'];
    }
}