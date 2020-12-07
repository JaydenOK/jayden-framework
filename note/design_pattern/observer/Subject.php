<?php


namespace module\design_pattern\observer;


class Subject
{
    /**
     * @var Sender[]
     */
    protected $senders = [];

    public function attach(Sender $sender)
    {
        array_push($this->senders, $sender);
    }

    /**
     * 移除观察者
     * 使用全等运算符（===），这两个对象变量一定要指向某个类的同一个实例（即同一个对象）。
     * @param Sender $sender
     */
    public function detach(Sender $sender)
    {
        foreach ($this->senders as $key => $senderItem) {
            if ($sender === $senderItem) {
                unset($this->senders[$key]);
            }
        }
    }

    /**
     * 通知所有观察者
     * @return bool
     */
    public function notify()
    {
        foreach ($this->senders as $sender) {
            $sender->send();
        }
        return true;
    }
}