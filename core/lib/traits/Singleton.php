<?php

namespace app\core\lib\traits;

trait Singleton
{
    private static $instance;

    public static function getInstance(...$args)
    {
        if (!isset(self::$instance)) {
            self::$instance = new static(...$args);
        }
        return self::$instance;
    }
}