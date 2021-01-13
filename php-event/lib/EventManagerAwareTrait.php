<?php

namespace module\lib;

trait EventManagerAwareTrait
{
    /**
     * @var EventManager|EventManagerInterface
     */
    protected $eventManager;

    /**
     * @param bool $createIfNotExists
     * @return EventManager|EventManagerInterface
     * @throws \InvalidArgumentException
     */
    public function getEventManager($createIfNotExists = true)
    {
        if (!$this->eventManager && $createIfNotExists) {
            $this->setEventManager(new EventManager());
        }

        return $this->eventManager;
    }

    /**
     * @param EventManager|EventManagerInterface $eventManager
     */
    public function setEventManager(EventManagerInterface $eventManager)
    {
        $this->eventManager = $eventManager;

        if (\method_exists($this, 'attachDefaultListeners')) {
            $this->attachDefaultListeners($eventManager);
        }
    }

    /**
     * @param  string|EventInterface $event 'app.start' 'app.stop'
     * @param  mixed|string          $target
     * @param  array|mixed           $args
     * @return mixed
     */
    public function trigger($event, $target = null, array $args = [])
    {
        if ($this->eventManager) {
            return $this->eventManager->trigger($event, $target, $args);
        }

        return $event;
    }
}