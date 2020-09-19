<?php

namespace app\core\lib\exception;


class InvalidValueException extends \UnexpectedValueException
{
    public function getName()
    {
        return 'Invalid Value';
    }
}