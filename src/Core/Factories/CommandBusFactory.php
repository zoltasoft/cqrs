<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Factories;

use Psr\Container\ContainerInterface;
use Zolta\Cqrs\Commands\Contracts\CommandBusInterface;
use Zolta\Cqrs\Commands\EventDispatchingCommandBus;
use Zolta\Cqrs\Commands\QueuedCommandBus;
use Zolta\Cqrs\Commands\SynchronousCommandBus;
use Zolta\Cqrs\Commands\ValidatingCommandBus;
use Zolta\Cqrs\Commands\WorkerAwareRoutingCommandBus;
use Zolta\Cqrs\Events\Contracts\EventDispatcherInterface;

class CommandBusFactory
{
    /**
     * Build a fully configured command bus stack.
     */
    /**
     * @param  array<class-string, array{handler?: string|object, validator?: string|object}>  $commandMap
     */
    public static function create(ContainerInterface $container, array $commandMap, ?ContainerInterface $handlerLocator = null): CommandBusInterface
    {
        $handlerLocator ??= $container;

        // --------------------------
        // Base synchronous bus
        // --------------------------
        $synchronousCommandBus = new SynchronousCommandBus($container, [], $handlerLocator);
        foreach ($commandMap as $command => $m) {
            if (! empty($m['handler'])) {
                $synchronousCommandBus->register($command, $m['handler']);
            }
        }

        // --------------------------
        // Validation decorator
        // --------------------------
        $validatingCommandBus = new ValidatingCommandBus($synchronousCommandBus, $container, $handlerLocator);
        foreach ($commandMap as $command => $m) {
            if (! empty($m['validator'])) {
                $validatingCommandBus->registerValidator($command, $m['validator']);
            }
        }

        // --------------------------
        // Event dispatching decorator
        // --------------------------
        $eventDispatcher = $container->get(EventDispatcherInterface::class);
        $eventDispatchingCommandBus = new EventDispatchingCommandBus($validatingCommandBus, $eventDispatcher);

        // --------------------------
        // Queue support
        // --------------------------
        $queuedCommandBus = new QueuedCommandBus($validatingCommandBus);

        // --------------------------
        // Worker-aware routing
        // --------------------------
        return new WorkerAwareRoutingCommandBus($eventDispatchingCommandBus, $queuedCommandBus);
    }
}
