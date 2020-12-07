<?php


namespace module\design_pattern\observer;


class MailSender implements Sender
{

    public function send()
    {
        // TODO: Implement send() method.
        echo '执行发送邮件.';
    }
}