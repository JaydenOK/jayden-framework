<?php


namespace app\core\lib;

class Language
{
    const MESSAGE_FILE = 'Message.php';

    protected $lang = 'cn';

    public static function getMessage($code)
    {
        $config = App::getInstance()->getConfig();
        $language = $config->get('language');
        $message = include(APP_ROOT . DS . 'language' . DS . $language . DS . static::MESSAGE_FILE);
        return (new Config($message))->get($code);
    }
}