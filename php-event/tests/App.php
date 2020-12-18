<?php

namespace module\tests;

use module\lib\Event;
use module\lib\EventManager;
use module\lib\EventManagerAwareTrait;

class App
{
    use EventManagerAwareTrait;

    const ON_START          = 'app.start';
    const ON_STOP           = 'app.stop';
    const ON_BEFORE_REQUEST = 'app.beforeRequest';

    const ON_AFTER_REQUEST = 'app.afterRequest';

    public function __construct(EventManager $em)
    {
        $this->setEventManager($em);

        $this->eventManager->trigger(new Event(self::ON_START, [
            'key' => 'val'
        ]));
    }

    public function run()
    {
        $sleep = 0;
        $this->eventManager->trigger(self::ON_BEFORE_REQUEST);

        echo 'request handling ';
        while ($sleep <= 3) {
            $sleep++;
            echo '.';
            sleep(1);
        }
        echo "\n";

        $this->eventManager->trigger(self::ON_AFTER_REQUEST);
    }

    public function __destruct()
    {
        $this->eventManager->trigger(new Event(self::ON_STOP, [
            'key1' => 'val1'
        ]));
    }
}
