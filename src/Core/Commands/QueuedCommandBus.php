<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Commands;

use Zolta\Cqrs\Commands\Contracts\CommandBusInterface;
use Zolta\Cqrs\Commands\Contracts\CommandInterface;

/**
 * A CommandBus implementation that queues commands for asynchronous processing.
 *
 * This bus acts as a decorator, wrapping another bus and using the underlying
 * framework's queueing system to dispatch commands. It delegates the `register`
 * call to the decorated bus, ensuring that the handler is correctly mapped
 * for when the command is eventually processed.
 */
class QueuedCommandBus implements CommandBusInterface
{
    public function __construct(
        private readonly CommandBusInterface $commandBus
    ) {}

    public function dispatch(CommandInterface $command, ...$args): mixed
    {
        // enqueue the job, pass context in job if needed
        ExecuteCommandJob::dispatch($command, ...$args);

        return true;
    }

    public function register(string $command, object|string $handler): void
    {
        // We delegate the register call to the
        // decorated bus. This is crucial because when the command is later
        // pulled from the queue, the final bus in the chain (the synchronous
        // one) will need to know which handler to execute.
        $this->commandBus->register($command, $handler);
    }
}
