<?php

declare(strict_types=1);

namespace Zolta\Tests\Unit\Queries;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Zolta\Cqrs\Queries\Contracts\QueryInterface;
use Zolta\Cqrs\Queries\InMemoryQueryBus;

// ── Test doubles ────────────────────────────────────────────────────────

class GetUserQuery implements QueryInterface
{
    public function __construct(public readonly int $id) {}
}

class GetUserByEmailQuery implements QueryInterface
{
    public function __construct(public readonly string $email) {}
}

class GetUserHandler
{
    /** @return array<string, mixed> */
    public function __invoke(GetUserQuery $query): array
    {
        return ['id' => $query->id, 'name' => 'User #' . $query->id];
    }
}

class HandleMethodQueryHandler
{
    /** @return array<string, mixed> */
    public function handle(GetUserQuery $query): array
    {
        return ['id' => $query->id, 'via' => 'handle'];
    }
}

// ── Tests ────────────────────────────────────────────────────────────────

final class InMemoryQueryBusTest extends TestCase
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
                    throw new RuntimeException("Not found: {$id}");
                }

                return $this->bindings[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->bindings[$id]);
            }
        };
    }

    public function test_ask_with_invokable_handler(): void
    {
        $handler = new GetUserHandler;
        $container = $this->makeContainer([
            GetUserHandler::class => $handler,
        ]);

        $bus = new InMemoryQueryBus($container);
        $bus->register(GetUserQuery::class, GetUserHandler::class);

        $result = $bus->ask(new GetUserQuery(42));

        $this->assertSame(['id' => 42, 'name' => 'User #42'], $result);
    }

    public function test_ask_with_handle_method(): void
    {
        $handler = new HandleMethodQueryHandler;
        $container = $this->makeContainer([
            HandleMethodQueryHandler::class => $handler,
        ]);

        $bus = new InMemoryQueryBus($container);
        $bus->register(GetUserQuery::class, HandleMethodQueryHandler::class);

        $result = $bus->ask(new GetUserQuery(7));

        $this->assertSame(['id' => 7, 'via' => 'handle'], $result);
    }

    public function test_throws_when_no_handler_registered(): void
    {
        $container = $this->makeContainer();
        $bus = new InMemoryQueryBus($container);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No handler registered');

        $bus->ask(new GetUserQuery(1));
    }

    public function test_throws_when_handler_not_resolvable(): void
    {
        $container = $this->makeContainer();
        $bus = new InMemoryQueryBus($container);
        $bus->register(GetUserQuery::class, 'Non\Existent\Handler'); // @phpstan-ignore argument.type

        $this->expectException(RuntimeException::class);

        $bus->ask(new GetUserQuery(1));
    }

    public function test_register_overrides_previous_handler(): void
    {
        $handler1 = new GetUserHandler;
        $handler2 = new HandleMethodQueryHandler;

        $container = $this->makeContainer([
            GetUserHandler::class => $handler1,
            HandleMethodQueryHandler::class => $handler2,
        ]);

        $bus = new InMemoryQueryBus($container);
        $bus->register(GetUserQuery::class, GetUserHandler::class);
        $bus->register(GetUserQuery::class, HandleMethodQueryHandler::class);

        $result = $bus->ask(new GetUserQuery(1));
        $this->assertSame('handle', $result['via']);
    }
}
