<?php


namespace app\docs_md\note\design_pattern\observer;


class AppMessageSender implements Sender
{

    public function send()
    {
        // TODO: Implement send() method.
        echo '执行发送应用消息.';
    }
}