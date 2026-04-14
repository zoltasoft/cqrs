<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Commands;

use Zolta\Cqrs\Commands\Contracts\CommandBusInterface;
use Zolta\Cqrs\Commands\Contracts\CommandInterface;
use Zolta\Cqrs\Commands\Interfaces\ShouldQueue;
use Zolta\Cqrs\Laravel\LaravelAdapter;
use Zolta\Framework\FrameworkRegistry;

/**
 * Public-facing bus that:
 *  - Outside workers: routes by ShouldQueue (async vs sync).
 *  - Inside workers: ALWAYS uses sync to avoid re-enqueue loops.
 */
class WorkerAwareRoutingCommandBus implements CommandBusInterface
{
    public function __construct(
        private readonly CommandBusInterface $syncBus,   // e.g. EventDispatchingCommandBus
        private readonly CommandBusInterface $asyncBus   // e.g. QueuedCommandBus
    ) {}

    public function dispatch(CommandInterface $command, ...$args): mixed
    {
        // Laravel-only worker flag; skip for other frameworks.
        if (FrameworkRegistry::resolve() === LaravelAdapter::class && function_exists('app')) {
            $app = app();
            if (is_object($app) && method_exists($app, 'bound') && $app->bound('zolta.commandbus.in_worker') && $app('zolta.commandbus.in_worker') === true) {
                return $this->syncBus->dispatch($command, ...$args);
            }
        }

        if ($command instanceof ShouldQueue) {
            return $this->asyncBus->dispatch($command, ...$args);
        }

        return $this->syncBus->dispatch($command, ...$args);
    }

    public function register(string $command, object|string $handler): void
    {
        // Handlers live on the sync path
        $this->syncBus->register($command, $handler);
    }
}
