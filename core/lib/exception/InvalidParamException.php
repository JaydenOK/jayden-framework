<?php

namespace app\core\lib\exception;

class InvalidParamException extends \BadMethodCallException
{
    public function getName()
    {
        return 'Invalid Parameter';
    }
}