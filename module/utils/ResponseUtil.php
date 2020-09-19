<?php

namespace app\module\utils;

use app\core\lib\App;
use app\core\lib\exception\Exception;
use app\core\lib\exception\InvalidValueException;

class ResponseUtil
{

    /**
     *
     * @param Exception $e
     * @return array
     */
    public static function getOutputArrayByException(Exception $e)
    {
        return static::getOutputArrayByCodeAndMessage($e->getCode(), $e->getMessage());
    }

    /**
     *
     * @param integer $code
     * @param string $message
     * @return array
     */
    public static function getOutputArrayByCodeAndMessage($code, $message)
    {
        return [
            'code' => $code,
            'message' => $message
        ];
    }

    /**
     *
     * @param integer $code
     * @return array
     */
    public static function getOutputArrayByCode($code)
    {
        return [
            'code' => $code,
            'message' => App::getInstance()->translate($code)
        ];
    }

    /**
     *
     * @param integer $code
     * @param array $data
     * @return array
     */
    public static function getOutputArrayByCodeAndData($code, $data)
    {
        return array_merge(static::getOutputArrayByCode($code), ['data' => $data]);
    }

    /**
     * 自定义报错
     * @param $code
     * @param string $msg
     */
    public static function throwInvalidValueExceptionByCode($code, $msg = '')
    {
        $response = ResponseUtil::getOutputArrayByCode($code);
        $msg = empty($msg) ? $response['message'] : $msg;
        throw new InvalidValueException($msg, $response['code']);
    }
}