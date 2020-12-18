<?php
/**
 * 触发一个监听者多个方法
 * 通过实现接口EventSubscriberInterface 的 getSubscribedEvents方法配置处理方法
 */

use module\lib\EventInterface;
use module\lib\EventManager;
use module\lib\EventManagerAwareTrait;
use module\lib\EventSubscriberInterface;
use module\lib\listener\ListenerPriority;

require '../bootstrap.php';


class EnumGroupListener implements EventSubscriberInterface
{
    const TEST_EVENT = 'test';
    const POST_EVENT = 'post';

    /**
     * 配置事件与对应的处理方法
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            self::TEST_EVENT => 'onTest',
            self::POST_EVENT => ['onPost', ListenerPriority::LOW],
        ];
    }

    public function onTest(EventInterface $event)
    {
        $pos = __METHOD__;
        echo "handle the event {$event->getName()} on the: $pos\n";
    }

    public function onPost(EventInterface $event)
    {
        $pos = __METHOD__;
        echo "handle the event {$event->getName()} on the: $pos\n";
    }
}


$em = new EventManager();

// register a group listener
$em->addListener(new EnumGroupListener());

class EnumGroup
{
    use EventManagerAwareTrait;

    public function run()
    {
        //可触发多个方法
        $this->eventManager->trigger(EnumGroupListener::TEST_EVENT);

        echo '.';
        sleep(1);
        echo ".\n";

        $this->eventManager->trigger(EnumGroupListener::POST_EVENT);
    }
}

$demo = new EnumGroup();
$demo->setEventManager($em);
$demo->run();

