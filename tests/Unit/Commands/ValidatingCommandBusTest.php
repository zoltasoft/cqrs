<?php

declare(strict_types=1);

namespace Zolta\Tests\Unit\Commands;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Zolta\Cqrs\Commands\Contracts\CommandInterface;
use Zolta\Cqrs\Commands\Contracts\CommandResultInterface;
use Zolta\Cqrs\Commands\SynchronousCommandBus;
use Zolta\Cqrs\Commands\ValidatingCommandBus;
use Zolta\Cqrs\Services\Result;
use Zolta\Domain\Events\Contracts\EventInterface;

// ── Test fakes ──────────────────────────────────────────────────────────

class UpdateNameCommand implements CommandInterface
{
    public function __construct(public readonly string $name) {}
}

class UpdateNameHandler
{
    public function __invoke(UpdateNameCommand $command): Result
    {
        $event = new class implements EventInterface
        {
            public function occurredOn(): \DateTimeImmutable
            {
                return new \DateTimeImmutable;
            }
        };

        return Result::success(['name' => $command->name], [$event]);
    }
}

class UpdateNameValidator
{
    public function validate(UpdateNameCommand $command): ?CommandResultInterface
    {
        if (trim($command->name) === '') {
            return Result::failure(new \InvalidArgumentException('Name cannot be empty'));
        }

        return null;
    }
}

class FailingNameValidator
{
    public function validate(UpdateNameCommand $command): CommandResultInterface
    {
        return Result::failure(new \DomainException('Always fails'));
    }
}

// ── Tests ────────────────────────────────────────────────────────────────

final class ValidatingCommandBusTest extends TestCase
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

    public function test_valid_command_passes_through_to_inner_bus(): void
    {
        $handler = new UpdateNameHandler;
        $validator = new UpdateNameValidator;

        $container = $this->makeContainer([
            UpdateNameHandler::class => $handler,
            UpdateNameValidator::class => $validator,
        ]);

        $syncBus = new SynchronousCommandBus($container, [
            UpdateNameCommand::class => $handler,
        ]);

        $validatingBus = new ValidatingCommandBus($syncBus, $container);
        $validatingBus->registerValidator(UpdateNameCommand::class, UpdateNameValidator::class);

        $result = $validatingBus->dispatch(new UpdateNameCommand('Alice'));

        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('Alice', $result->getValue()['name']);
    }

    public function test_invalid_command_returns_failure_without_dispatching(): void
    {
        $handler = new UpdateNameHandler;
        $validator = new UpdateNameValidator;

        $container = $this->makeContainer([
            UpdateNameHandler::class => $handler,
            UpdateNameValidator::class => $validator,
        ]);

        $syncBus = new SynchronousCommandBus($container, [
            UpdateNameCommand::class => $handler,
        ]);

        $validatingBus = new ValidatingCommandBus($syncBus, $container);
        $validatingBus->registerValidator(UpdateNameCommand::class, UpdateNameValidator::class);

        $result = $validatingBus->dispatch(new UpdateNameCommand(''));

        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->isFailure());
        $this->assertInstanceOf(\InvalidArgumentException::class, $result->getError());
    }

    public function test_command_without_validator_dispatches_normally(): void
    {
        $handler = new UpdateNameHandler;
        $container = $this->makeContainer([
            UpdateNameHandler::class => $handler,
        ]);

        $syncBus = new SynchronousCommandBus($container, [
            UpdateNameCommand::class => $handler,
        ]);

        $validatingBus = new ValidatingCommandBus($syncBus, $container);
        // No validator registered

        $result = $validatingBus->dispatch(new UpdateNameCommand('Bob'));

        $this->assertTrue($result->isSuccess());
    }

    public function test_register_validator_rejects_non_existent_command_class(): void
    {
        $container = $this->makeContainer();
        $syncBus = new SynchronousCommandBus($container);
        $validatingBus = new ValidatingCommandBus($syncBus, $container);

        $this->expectException(\InvalidArgumentException::class);
        $validatingBus->registerValidator('Non\\Existent\\Command', UpdateNameValidator::class);
    }

    public function test_register_validator_rejects_non_existent_validator_class(): void
    {
        $container = $this->makeContainer();
        $syncBus = new SynchronousCommandBus($container);
        $validatingBus = new ValidatingCommandBus($syncBus, $container);

        $this->expectException(\InvalidArgumentException::class);
        $validatingBus->registerValidator(UpdateNameCommand::class, 'Non\\Existent\\Validator');
    }

    public function test_register_delegates_to_inner_bus(): void
    {
        $handler = new UpdateNameHandler;
        $container = $this->makeContainer([
            UpdateNameHandler::class => $handler,
        ]);

        $syncBus = new SynchronousCommandBus($container);
        $validatingBus = new ValidatingCommandBus($syncBus, $container);

        $validatingBus->register(UpdateNameCommand::class, $handler);

        $result = $validatingBus->dispatch(new UpdateNameCommand('Charlie'));
        $this->assertTrue($result->isSuccess());
    }
}
