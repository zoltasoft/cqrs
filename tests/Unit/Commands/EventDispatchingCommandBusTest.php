<?php

declare(strict_types=1);

namespace Zolta\Tests\Unit\Commands;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Zolta\Cqrs\Commands\EventDispatchingCommandBus;
use Zolta\Cqrs\Commands\SynchronousCommandBus;
use Zolta\Cqrs\Events\Contracts\EventDispatcherInterface;
use Zolta\Cqrs\Services\Result;
use Zolta\Domain\Events\Contracts\EventInterface;

final class EventDispatchingCommandBusTest extends TestCase
{
    /** @param array<string, mixed> $bindings */
    private function makeContainer(array $bindings = []): ContainerInterface
    {
        return new class($bindings) implements ContainerInterface
        {
            /** @param array<string, mixed> $bindings */
            public function __construct(private readonly array $bindings) {}

            public function get(string $id): mixed
            {
                if (! isset($this->bindings[$id])) {
                    throw new \RuntimeException("Not found: {$id}");
                }

                return $this->bindings[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->bindings[$id]);
            }
        };
    }

    public function test_events_dispatched_on_success(): void
    {
        $handler = new UpdateNameHandler;
        $container = $this->makeContainer([
            UpdateNameHandler::class => $handler,
        ]);

        $syncBus = new SynchronousCommandBus($container, [
            UpdateNameCommand::class => $handler,
        ]);

        $dispatched = [];
        $eventDispatcher = new class($dispatched) implements EventDispatcherInterface
        {
            /** @param array<int, EventInterface> $dispatched */
            public function __construct(private array &$dispatched) {}

            public function dispatch(EventInterface $event): void
            {
                $this->dispatched[] = $event;
            }

            /** @return array<int, EventInterface> */
            public function getDispatched(): array
            {
                return $this->dispatched;
            }

            public function registerListeners(array $listeners): void {}

            public function listen(string|array $events, callable|string $listener): void {}
        };

        $bus = new EventDispatchingCommandBus($syncBus, $eventDispatcher);

        $result = $bus->dispatch(new UpdateNameCommand('Alice'));

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $eventDispatcher->getDispatched());
    }

    public function test_events_not_dispatched_on_failure(): void
    {
        $failingHandler = new class
        {
            public function __invoke(UpdateNameCommand $command): Result
            {
                return Result::failure(new \RuntimeException('fail'));
            }
        };

        $container = $this->makeContainer();
        $syncBus = new SynchronousCommandBus($container, [
            UpdateNameCommand::class => $failingHandler,
        ]);

        $dispatched = [];
        $eventDispatcher = new class($dispatched) implements EventDispatcherInterface
        {
            /** @param array<int, EventInterface> $dispatched */
            public function __construct(private array &$dispatched) {}

            public function dispatch(EventInterface $event): void
            {
                $this->dispatched[] = $event;
            }

            /** @return array<int, EventInterface> */
            public function getDispatched(): array
            {
                return $this->dispatched;
            }

            public function registerListeners(array $listeners): void {}

            public function listen(string|array $events, callable|string $listener): void {}
        };

        $bus = new EventDispatchingCommandBus($syncBus, $eventDispatcher);

        $result = $bus->dispatch(new UpdateNameCommand('X'));

        $this->assertTrue($result->isFailure());
        $this->assertCount(0, $eventDispatcher->getDispatched());
    }

    public function test_register_delegates_to_inner_bus(): void
    {
        $handler = new UpdateNameHandler;
        $container = $this->makeContainer();

        $syncBus = new SynchronousCommandBus($container);

        $dispatched = [];
        $eventDispatcher = new class($dispatched) implements EventDispatcherInterface
        {
            /** @param array<int, EventInterface> $dispatched */
            public function __construct(private array &$dispatched) {}

            public function dispatch(EventInterface $event): void
            {
                $this->dispatched[] = $event;
            }

            /** @return array<int, EventInterface> */
            public function getDispatched(): array
            {
                return $this->dispatched;
            }

            public function registerListeners(array $listeners): void {}

            public function listen(string|array $events, callable|string $listener): void {}
        };

        $bus = new EventDispatchingCommandBus($syncBus, $eventDispatcher);
        $bus->register(UpdateNameCommand::class, $handler);

        $result = $bus->dispatch(new UpdateNameCommand('Eve'));
        $this->assertTrue($result->isSuccess());
    }
}
