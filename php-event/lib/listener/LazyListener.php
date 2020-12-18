<?php

namespace module\lib\listener;

use module\lib\EventHandlerInterface;
use module\lib\EventInterface;

/**
 * Class LazyListener - 将callable包装成对象
 * @package Inhere\Event\Listener
 */
class LazyListener implements EventHandlerInterface
{
    /**
     * @var callable
     */
    private $callback;

    /**
     * @param callable $callback
     * @return LazyListener
     */
    public static function create(callable $callback): self
    {
        return new self($callback);
    }

    /**
     * LazyListener constructor.
     * @param callable $callback
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    /**
     * @param EventInterface $event
     * @return mixed
     */
    public function handle(EventInterface $event)
    {
        return ($this->callback)($event);
    }

    /**
     * @return callable
     */
    public function getCallback()
    {
        return $this->callback;
    }
}
