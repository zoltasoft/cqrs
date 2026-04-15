<?php

declare(strict_types=1);

namespace Zolta\Tests\Unit\Commands;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Zolta\Cqrs\Commands\Contracts\CommandInterface;
use Zolta\Cqrs\Commands\SynchronousCommandBus;
use Zolta\Cqrs\Services\Result;

// ── Test doubles ────────────────────────────────────────────────────────

class CreateUserCommand implements CommandInterface
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
    ) {}
}

class DeleteUserCommand implements CommandInterface
{
    public function __construct(public readonly int $id) {}
}

class CreateUserHandler
{
    public function __invoke(CreateUserCommand $command): Result
    {
        return Result::success(['name' => $command->name, 'email' => $command->email]);
    }
}

class HandleMethodHandler
{
    public function handle(CreateUserCommand $command): Result
    {
        return Result::success(['handled' => $command->name]);
    }
}

// ── Tests ────────────────────────────────────────────────────────────────

final class SynchronousCommandBusTest extends TestCase
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

    public function test_dispatch_with_invokable_handler_instance(): void
    {
        $container = $this->makeContainer();
        $handler = new CreateUserHandler;

        $bus = new SynchronousCommandBus($container, [
            CreateUserCommand::class => $handler,
        ]);

        $command = new CreateUserCommand('Alice', 'alice@example.com');
        $result = $bus->dispatch($command);

        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('Alice', $result->getValue()['name']);
    }

    public function test_dispatch_with_handle_method_handler(): void
    {
        $container = $this->makeContainer();
        $handler = new HandleMethodHandler;

        $bus = new SynchronousCommandBus($container, [
            CreateUserCommand::class => $handler,
        ]);

        $command = new CreateUserCommand('Bob', 'bob@test.com');
        $result = $bus->dispatch($command);

        $this->assertInstanceOf(Result::class, $result);
        $this->assertSame('Bob', $result->getValue()['handled']);
    }

    public function test_dispatch_with_string_handler_resolved_from_container(): void
    {
        $handler = new CreateUserHandler;
        $container = $this->makeContainer([
            CreateUserHandler::class => $handler,
        ]);

        $bus = new SynchronousCommandBus($container, [
            CreateUserCommand::class => CreateUserHandler::class,
        ]);

        $command = new CreateUserCommand('Charlie', 'c@test.com');
        $result = $bus->dispatch($command);

        $this->assertTrue($result->isSuccess());
    }

    public function test_throws_when_no_handler_registered(): void
    {
        $container = $this->makeContainer();
        $bus = new SynchronousCommandBus($container);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No handler registered');

        $bus->dispatch(new CreateUserCommand('X', 'x@x.com'));
    }

    public function test_throws_when_string_handler_not_in_container(): void
    {
        $container = $this->makeContainer();

        $bus = new SynchronousCommandBus($container, [
            CreateUserCommand::class => 'NonExistent\\Handler',
        ]);

        $this->expectException(RuntimeException::class);
        $bus->dispatch(new CreateUserCommand('X', 'x@x.com'));
    }

    public function test_register_adds_handler_at_runtime(): void
    {
        $container = $this->makeContainer();
        $bus = new SynchronousCommandBus($container);

        $bus->register(CreateUserCommand::class, new CreateUserHandler);

        $result = $bus->dispatch(new CreateUserCommand('Eve', 'eve@test.com'));
        $this->assertTrue($result->isSuccess());
    }

    public function test_non_result_return_wrapped_in_success(): void
    {
        $handler = new class
        {
            /** @return array<string, mixed> */
            public function __invoke(CreateUserCommand $command): array
            {
                return ['raw' => true];
            }
        };

        $container = $this->makeContainer();
        $bus = new SynchronousCommandBus($container, [
            CreateUserCommand::class => $handler,
        ]);

        $result = $bus->dispatch(new CreateUserCommand('X', 'x@x.com'));
        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->isSuccess());
    }
}
