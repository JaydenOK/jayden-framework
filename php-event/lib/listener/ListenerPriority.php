<?php

namespace module\lib\listener;

/**
 * Class ListenerPriority - 监听器优先级级别 部分常量
 */
class ListenerPriority
{
    public const MIN          = -300;
    public const LOW          = -200;
    public const BELOW_NORMAL = -100;
    public const NORMAL       = 0;
    public const ABOVE_NORMAL = 100;
    public const HIGH         = 200;
    public const MAX          = 300;
}
