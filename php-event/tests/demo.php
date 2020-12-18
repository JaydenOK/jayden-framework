<?php

//事件(Event) 是传递于应用代码与 监听器(Listener) 之间的通讯对象
//监听器(Listener) 是用于监听 事件(Event) 的发生的监听对象
//事件调度器(EventDispatcher) 是用于触发 事件(Event) 和管理 监听器(Listener) 与 事件(Event) 之间的关系的管理者对象

use module\lib\Event;
use module\lib\EventHandlerInterface;
use module\lib\EventInterface;
use module\lib\EventManager;
use module\lib\EventManagerAwareTrait;

require '../bootstrap.php';

function exam_handler(EventInterface $event)
{
    $pos = __METHOD__;
    echo "handle the event '{$event->getName()}' on the: $pos \n";
}

class ExamHandler implements EventHandlerInterface
{
    /**
     * @param EventInterface $event
     * @return mixed
     */
    public function handle(EventInterface $event)
    {
        $pos = __METHOD__;
        echo "handle the event '{$event->getName()}' on the: $pos\n";

        return true;
    }
}

class ExamListener1
{
    public function messageSent(EventInterface $event)
    {
        $pos = __METHOD__;

        echo "handle the event '{$event->getName()}' on the: $pos \n";
    }
}

class ExamListener2
{
    public function __invoke(EventInterface $event)
    {
        // $event->stopPropagation(true);
        $pos = __METHOD__;
        echo "handle the event '{$event->getName()}' on the: $pos\n";
    }
}


// create event class
class MessageEvent extends Event
{
    // append property ...
    public $message = 'oo a text';
}

class Mailer
{
    use EventManagerAwareTrait;

    const EVENT_MESSAGE_SENT = 'messageSent';

    public function send($message)
    {
        // ...发送 $message 的逻辑...

        $event = new MessageEvent(self::EVENT_MESSAGE_SENT);
        $event->message = $message;

        // trigger event
        $this->eventManager->trigger($event);

        // var_dump($event);
    }

    //默认事件
    public function attachDefaultListeners()
    {
        echo 'aaa' . "\r\n";
    }

}


//事件调度器（EventManager）
$em = new EventManager();

//添加事件监听器（ExamListener1，ExamHandler）
$em->attach(Mailer::EVENT_MESSAGE_SENT, 'exam_handler');

$em->attach(Mailer::EVENT_MESSAGE_SENT, function (EventInterface $event) {
    $pos = __METHOD__;
    echo "handle the event '{$event->getName()}' on the: $pos\n";
});

$em->attach(Mailer::EVENT_MESSAGE_SENT, new ExamListener1(), 10);
$em->attach(Mailer::EVENT_MESSAGE_SENT, new ExamListener2());
$em->attach(Mailer::EVENT_MESSAGE_SENT, new ExamHandler());

$em->attach('*', function (EventInterface $event) {
    echo "handle the event '{$event->getName()}' on the global listener.\n";
});

//事件（Mailer）
$mailer = new Mailer();
//事件设置 关联 事件调度器
$mailer->setEventManager($em);

//事件触发方法send，里边触发事件调度器trigger方法：$this->eventManager->trigger($event);
$mailer->send('hello, world!');

/*
handle the event 'messageSent' on the: ExamListener1::messageSent
handle the event 'messageSent' on the: exam_handler
handle the event 'messageSent' on the: {closure}
handle the event 'messageSent' on the: ExamListener2::__invoke
handle the event 'messageSent' on the: Inhere\Event\Examples\ExamHandler::handle
handle the event 'messageSent' on the global listener.
 */