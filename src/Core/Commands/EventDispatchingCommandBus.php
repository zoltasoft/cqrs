<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Commands;

use Zolta\Cqrs\Commands\Contracts\CommandBusInterface;
use Zolta\Cqrs\Commands\Contracts\CommandInterface;
use Zolta\Cqrs\Commands\Contracts\CommandResultInterface;
use Zolta\Cqrs\Events\Contracts\EventDispatcherInterface;

/**
 * A CommandBus implementation that automatically dispatches domain events.
 *
 * This is a decorator that executes a command and then dispatches any
 * events contained within the returned CommandResult.
 */
class EventDispatchingCommandBus implements CommandBusInterface
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {}

    public function dispatch(CommandInterface $command, ...$args): mixed
    {
        $result = $this->commandBus->dispatch($command, ...$args);

        if ($result instanceof CommandResultInterface && $result->isSuccess()) {
            foreach ($result->getEvents() as $event) {
                $this->eventDispatcher->dispatch($event);
            }
        }

        return $result;
    }

    public function register(string $command, object|string $handler): void
    {
        // This method must delegate to the decorated bus to ensure the handler is
        // registered on the final bus in the chain.
        $this->commandBus->register($command, $handler);
    }
}
