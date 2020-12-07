<?php


namespace module\design_pattern\observer;


class AppMessageSender implements Sender
{

    public function send()
    {
        // TODO: Implement send() method.
        echo '执行发送应用消息.';
    }
}