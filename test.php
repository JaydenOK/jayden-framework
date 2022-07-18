<?php

/**
 *
 * Class 用户类
 */
class 用户类
{
    public $名称 = '';

    public function __construct($名称)
    {
        $this->名称 = $名称;
    }

    public function 获取名称()
    {
        return $this->名称;
    }

    public function __toString()
    {
        return $this->获取名称();
    }
}

echo new 用户类("我是好人");