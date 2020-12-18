<?php

namespace module\lib;

/**
 * Class EventSubscriberInterface - 自定义配置多个相关的事件的监听器
 */

interface EventSubscriberInterface
{
    /**
     * 配置事件与对应的处理方法(可以配置优先级)
     * @return array
     */
    public static function getSubscribedEvents(): array;
    // {
    //     return [
    //         // event name => handler, can with priority
    //         KernelEvents::CONTROLLER => ['onKernelController', ListenerPriority::LOW],
    //         KernelEvents::VIEW => 'onKernelView',
    //     ];
    // }
}
