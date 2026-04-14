<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Events\Contracts;

use Zolta\Domain\Events\Contracts\EventInterface;

interface EventDispatcherInterface
{
    public function dispatch(EventInterface $event): void;

    /**
     * @param  array<string, callable>  $listeners
     */
    public function registerListeners(array $listeners): void;

    /**
     * @param  array<string, callable>|string  $events
     */
    public function listen(string|array $events, callable|string $listener): void;
}
