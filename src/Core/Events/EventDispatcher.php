<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Events;

use Zolta\Cqrs\Events\Contracts\EventDispatcherInterface;
use Zolta\Domain\Events\Contracts\EventInterface;

class EventDispatcher implements EventDispatcherInterface
{
    /**
     * @var array<string, callable[]>
     */
    private array $listeners = [];

    public function __construct(
        /**
         * @var EventDispatcherInterface[]
         */
        private readonly array $dispatchers
    ) {}

    public function dispatch(EventInterface $event): void
    {
        foreach ($this->dispatchers as $dispatcher) {
            $dispatcher->dispatch($event);
        }
    }

    public function listen(string|array $events, callable|string $listener): void
    {
        $events = is_array($events) ? $events : [$events];

        foreach ($events as $event) {
            if (! isset($this->listeners[$event])) {
                $this->listeners[$event] = [];
            }
            $this->listeners[$event][] = $listener;
        }
    }

    public function registerListeners(array $listeners): void
    {
        foreach ($listeners as $event => $eventListeners) {
            if (! isset($this->listeners[$event])) {
                $this->listeners[$event] = [];
            }
            $this->listeners[$event] = array_merge($this->listeners[$event], (array) $eventListeners);
        }
    }
}
