<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Commands\Contracts;

/**
 * Core Command Bus API — now supports optional Context propagation.
 */
interface CommandBusInterface
{
    /**
     * Dispatch a command. The optional Context is passed to handlers that accept it.
     *
     * @param  mixed  ...$args
     */
    public function dispatch(CommandInterface $command, ...$args): mixed;

    /**
     * Register a handler for a command.
     */
    public function register(string $command, object|string $handler): void;
}
