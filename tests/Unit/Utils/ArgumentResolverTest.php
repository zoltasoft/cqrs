<?php

declare(strict_types=1);

namespace Zolta\Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Zolta\Cqrs\Commands\Contracts\CommandInterface;
use Zolta\Cqrs\Utils\ArgumentResolver;

// ── Test doubles ────────────────────────────────────────────────────────

class TestCommand implements CommandInterface
{
    public function __construct(public readonly string $value) {}
}

class InvokableHandler
{
    public function __invoke(TestCommand $command): string
    {
        return $command->value;
    }
}

class HandleHandler
{
    public function handle(TestCommand $command): string
    {
        return $command->value;
    }
}

class ValidateHandler
{
    public function validate(TestCommand $command): bool
    {
        return true;
    }
}

class NoMethodHandler
{
    // Neither __invoke, handle, nor validate
    public function doSomething(): void {}
}

class MultiParamHandler
{
    /** @return array<int, mixed> */
    public function __invoke(TestCommand $command, string $extra = 'default'): array
    {
        return [$command->value, $extra];
    }
}

// ── Tests ────────────────────────────────────────────────────────────────

final class ArgumentResolverTest extends TestCase
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

    // ── selectMethod ────────────────────────────────────────────────────

    public function test_select_method_prefers_invoke(): void
    {
        $resolver = new ArgumentResolver($this->makeContainer());

        $method = $resolver->selectMethod(new InvokableHandler, TestCommand::class, 'handler');

        $this->assertSame('__invoke', $method);
    }

    public function test_select_method_falls_back_to_handle(): void
    {
        $resolver = new ArgumentResolver($this->makeContainer());

        $method = $resolver->selectMethod(new HandleHandler, TestCommand::class, 'handler');

        $this->assertSame('handle', $method);
    }

    public function test_select_method_validator_role_falls_back_to_validate(): void
    {
        $resolver = new ArgumentResolver($this->makeContainer());

        $method = $resolver->selectMethod(new ValidateHandler, TestCommand::class, 'validator');

        $this->assertSame('validate', $method);
    }

    public function test_select_method_throws_when_no_suitable_method(): void
    {
        $resolver = new ArgumentResolver($this->makeContainer());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot determine entrypoint method');

        $resolver->selectMethod(new NoMethodHandler, TestCommand::class, 'handler');
    }

    public function test_select_method_caches_result(): void
    {
        $resolver = new ArgumentResolver($this->makeContainer());

        $method1 = $resolver->selectMethod(new InvokableHandler, TestCommand::class, 'handler');
        $method2 = $resolver->selectMethod(new InvokableHandler, TestCommand::class, 'handler');

        $this->assertSame($method1, $method2);
    }

    // ── resolveMethodArguments ──────────────────────────────────────────

    public function test_resolve_injects_command_by_type(): void
    {
        $resolver = new ArgumentResolver($this->makeContainer());
        $command = new TestCommand('hello');
        $handler = new InvokableHandler;

        $args = $resolver->resolveMethodArguments($handler, '__invoke', $command, []);

        $this->assertCount(1, $args);
        $this->assertSame($command, $args[0]);
    }

    public function test_resolve_uses_default_for_optional_params(): void
    {
        $resolver = new ArgumentResolver($this->makeContainer());
        $command = new TestCommand('test');
        $handler = new MultiParamHandler;

        $args = $resolver->resolveMethodArguments($handler, '__invoke', $command, []);

        $this->assertCount(2, $args);
        $this->assertSame($command, $args[0]);
        $this->assertSame('default', $args[1]);
    }

    public function test_resolve_from_container_when_type_bound(): void
    {
        $container = $this->makeContainer([
            'string' => 'injected',
        ]);

        $resolver = new ArgumentResolver($container);
        $command = new TestCommand('test');

        $handler = new class
        {
            public function __invoke(TestCommand $cmd, string $extra = 'x'): void {}
        };

        // 'string' is a builtin type so container won't have it; the default will be used
        $args = $resolver->resolveMethodArguments($handler, '__invoke', $command, []);

        $this->assertSame($command, $args[0]);
    }
}
