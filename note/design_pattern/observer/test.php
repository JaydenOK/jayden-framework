<?php


use module\design_pattern\observer\AppMessageSender;
use module\design_pattern\observer\MailSender;
use module\design_pattern\observer\Subject;

require dirname(dirname(dirname(__FILE__))) . "/bootstrap.php";

$subject = new Subject();

$mailSender = new MailSender();
$appMessageSender = new AppMessageSender();

//注册观察者
$subject->attach($mailSender);
$subject->attach($appMessageSender);

//当接收到事件时，通知观察者
$subject->notify();