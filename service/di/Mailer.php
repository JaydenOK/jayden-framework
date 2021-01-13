<?php

namespace app\service\di;

class Mailer
{
    public function mail($recipient, $content)
    {
        // send an email to the recipient
        echo 'do send mail';
    }
}