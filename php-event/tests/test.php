<?php

use module\lib\Event;
use module\lib\EventInterface;
use module\lib\EventManager;

require '../bootstrap.php';

$myEvent = new class extends Event
{
    protected $name = 'test';

    public $prop = 'value';
};

$myListener = new class
{
    public function __invoke(EventInterface $event)
    {
        echo "handle the event {$event->getName()}\n";
    }

    const ON_DB_UPDATE = 'onDbUpdate';

    public function onDbUpdate(EventInterface $event)
    {
        echo "handle the event {$event->getName()}, sql: {$event->getParam('sql')}\n";
    }
};

$mgr = new EventManager();

//
$mgr->attach('test', $myListener);
$evt = $mgr->trigger('test');


// auto bind method 'onDbUpdate'
$mgr->addListener($myListener);

$evt1 = $mgr->trigger($myListener::ON_DB_UPDATE, null, ['sql' => 'a sql string']);

//var_dump($evt1);
