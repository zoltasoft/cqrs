<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Services;

use Illuminate\Contracts\Events\Dispatcher as LaravelDispatcher;
use Psr\Log\LoggerInterface;
use Zolta\Cqrs\Events\Contracts\EventDispatcherInterface;
use Zolta\Domain\Events\Contracts\EventInterface;

class LaravelEventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private readonly LaravelDispatcher $laravelDispatcher,
        private readonly ?LoggerInterface $logger = null
    ) {}

    /**
     * Dispatch a domain event by instantiating and dispatching corresponding infra event(s).
     *
     * For each infraEventClass registered for the domain event:
     *   - instantiate via container so constructor DI is honored (we pass the domain event as an explicit parameter)
     *   - dispatch the resulting infra event through Laravel's dispatcher
     */
    public function dispatch(EventInterface $event): void
    {
        // Always dispatch the domain event so domain listeners can observe it directly.
        $this->laravelDispatcher->dispatch($event);
    }

    /**
     * Try to create infra event instance via container while providing the domain event.
     * Strategy:
     *  - If container can make($infraEventClass, ['domainEvent' => $event]) use that
     *  - Else try make($infraEventClass, [$event])
     *  - Else if class is instantiable, try new $infraEventClass($event)
     */
    protected function makeInfraEventInstance(string $infraClass, EventInterface $domainEvent): ?object
    {
        // If class doesn't exist, bail
        if (! class_exists($infraClass)) {
            return null;
        }

        // Attempt container resolution with named param
        try {
            // if the container is available via app() helper
            if (function_exists('app')) {
                // try named parameter 'domainEvent' first (recommended constructor param name)
                try {
                    return app()->make($infraClass, ['domainEvent' => $domainEvent]);
                } catch (\Throwable) {
                    // fallback to positional param
                    return app()->make($infraClass, [$domainEvent]);
                }
            }
        } catch (\Throwable) {
            // fallback below to direct new
        }

        // If we reach here, try direct instantiation (best-effort)
        try {
            return new $infraClass($domainEvent);
        } catch (\Throwable) {
            return null;
        }
    }

    public function listen(string|array $events, callable|string $listener): void
    {
        foreach ((array) $events as $event) {
            $this->laravelDispatcher->listen($event, $listener);
        }
    }

    public function registerListeners(array $listeners): void
    {
        foreach ($listeners as $event => $handlers) {
            foreach ((array) $handlers as $handler) {
                $this->laravelDispatcher->listen($event, [$handler, 'handle']);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logDebug(string $message, array $context = []): void
    {
        $this->logger?->debug($message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logError(string $message, array $context = []): void
    {
        $this->logger?->error($message, $context);
    }
}
