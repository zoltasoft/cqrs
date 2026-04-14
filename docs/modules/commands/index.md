---
title: Commands
description: Command bus pipeline with decorator-based middleware, validation, event dispatching, and queue integration.
navigation:
  title: Commands
  order: 1
---

# Commands

Commands represent **write operations** that change application state. They flow through a decorator pipeline that provides validation, event dispatching, and optional queue processing.

## CommandInterface

The marker interface for all commands:

```php
namespace Zolta\Cqrs\Commands\Contracts;

interface CommandInterface
{
    // Marker interface
}
```

## Command base class

```php
namespace Zolta\Cqrs\Commands;

abstract class Command implements CommandInterface
{
    use Normalizable;
}
```

The `Normalizable` trait provides `toArray()` for serialization.

### Defining a command

```php
<?php

declare(strict_types=1);

namespace App\Application\Commands\CreateUser;

use Zolta\Cqrs\Commands\Command;

class CreateUserCommand extends Command
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $password,
    ) {}
}
```

## CommandBusInterface

```php
namespace Zolta\Cqrs\Commands\Contracts;

interface CommandBusInterface
{
    public function dispatch(CommandInterface $command, ...$args): mixed;
    public function register(string $command, object|string $handler): void;
}
```

## Handler discovery

Handlers are discovered automatically via the `#[HandlesCommand]` attribute:

```php
use Zolta\Cqrs\Attributes\HandlesCommand;

#[HandlesCommand(CreateUserCommand::class)]
class CreateUserHandler
{
    public function __invoke(CreateUserCommand $command): Result
    {
        // Handle the command
    }
}
```

### `#[HandlesCommand]` attribute

```php
#[Attribute(Attribute::TARGET_CLASS)]
class HandlesCommand
{
    public function __construct(
        public string $commandClass,
        public ?string $methodName = null,
    );
}
```

| Property | Type | Description |
|----------|------|-------------|
| `commandClass` | `string` | The command class this handler processes |
| `methodName` | `?string` | Custom method name (default: `__invoke` or `handle`) |

### Handler method resolution

The `ArgumentResolver` determines which method to call:

1. `__invoke()` if the handler is callable
2. Method specified in `#[HandlesCommand]` attribute
3. Fallback: `handle()`

## Validation

Validators run **before** handlers. They are discovered via `#[ValidatesCommand]`:

```php
use Zolta\Cqrs\Attributes\ValidatesCommand;
use Zolta\Exceptions\ValidationException;

#[ValidatesCommand(CreateUserCommand::class)]
class CreateUserValidator
{
    public function __construct(
        private readonly UserRepositoryInterface $repository,
    ) {}

    public function validate(CreateUserCommand $command): void
    {
        $errors = [];

        if (strlen($command->name) < 2) {
            $errors['name'] = 'Name must be at least 2 characters.';
        }

        if ($this->repository->findByEmail($command->email)) {
            $errors['email'] = 'Email already registered.';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }
}
```

### `#[ValidatesCommand]` attribute

```php
#[Attribute(Attribute::TARGET_CLASS)]
class ValidatesCommand
{
    public function __construct(
        public string $commandClass,
        public ?string $methodName = null,
    );
}
```

Validator method resolution follows the same rules as handlers, with `validate()` as the default fallback.

## Result monad

Command handlers return `Result` to signal success or failure:

```php
namespace Zolta\Cqrs\Services;

class Result implements CommandResultInterface
{
    // Static constructors
    public static function success(mixed $value = null, array $events = []): Result;
    public static function successWithEvents(array $events = []): Result;
    public static function failure(Throwable $throwable, array $events = []): Result;

    // Query methods
    public function isSuccess(): bool;
    public function isFailure(): bool;
    public function getValue(): mixed;   // Throws on failure
    public function getError(): Throwable; // Throws on success

    // Unwrap with callbacks
    public function getOrFail(
        ?callable $onFailure = null,
        ?callable $onSuccess = null,
    ): mixed;

    // Event management
    public function getEvents(): array;    // Read events
    public function releaseEvents(): array; // Read and clear events

    // Serialization
    public function toArray(): array;
}
```

### Usage patterns

```php
// Success with data and events
return Result::success(
    value: $user->toArray(),
    events: $user->releaseEvents(),
);

// Success with events only
return Result::successWithEvents($aggregate->releaseEvents());

// Failure
return Result::failure(new \DomainException('User not found'));

// Consuming results
$result = $cqrs->dispatch($command);

if ($result->isSuccess()) {
    $userData = $result->getValue();
}

// With callbacks
$result->getOrFail(
    onFailure: fn(Throwable $e) => throw new NotFoundException($e->getMessage()),
    onSuccess: fn(mixed $value) => $value['id'],
);
```

## CommandResultInterface

```php
namespace Zolta\Cqrs\Commands\Contracts;

interface CommandResultInterface
{
    public function getEvents(): array;
    public function releaseEvents(): array;
    public function isSuccess(): bool;
    public function isFailure(): bool;
    public function getError(): Throwable;
    public function getValue(): mixed;
}
```

## Decorator pipeline

### SynchronousCommandBus

The base bus that resolves and executes handlers:

```php
// Internally:
// 1. Lookup handler from command map
// 2. Resolve handler from container
// 3. Use ArgumentResolver to find method + inject arguments
// 4. Execute handler method
// 5. Return result
```

### ValidatingCommandBus

Wraps the sync bus and runs validators first:

```php
// Flow:
// 1. Lookup validator for command class
// 2. If validator exists: resolve and execute
// 3. If validation throws → return Result::failure()
// 4. If passes → delegate to decorated bus
```

### EventDispatchingCommandBus

Extracts domain events from results and dispatches them:

```php
// Flow:
// 1. Delegate to decorated bus
// 2. If result implements CommandResultInterface:
//    → Extract events via releaseEvents()
//    → Dispatch each event via EventDispatcherInterface
// 3. Return result
```

### QueuedCommandBus

Enqueues commands for asynchronous execution:

```php
// Flow:
// 1. Create ExecuteCommandJob with command
// 2. Dispatch job to Laravel queue
// 3. Return true (fire-and-forget)
```

### WorkerAwareRoutingCommandBus

The top-level bus that routes sync vs async:

```php
// Flow:
// 1. Check if running inside a queue worker
//    (via app('zolta.commandbus.in_worker'))
// 2. If in worker → always use sync bus (prevents re-queuing)
// 3. If command implements ShouldQueue → use async bus
// 4. Otherwise → use sync bus
```

## Async commands

Mark a command as queueable:

```php
use Zolta\Cqrs\Commands\Command;
use Zolta\Cqrs\Commands\Interfaces\ShouldQueue;

class SendWelcomeEmailCommand extends Command implements ShouldQueue
{
    public function __construct(
        public readonly string $userId,
        public readonly string $email,
    ) {}
}
```

When dispatched, this command is automatically queued via `ExecuteCommandJob`. Inside the worker, it executes synchronously through the `ValidatingCommandBus → SynchronousCommandBus` chain.

## Argument injection

Handlers can receive additional container-resolved dependencies:

```php
#[HandlesCommand(CreateUserCommand::class)]
class CreateUserHandler
{
    public function __invoke(
        CreateUserCommand $command,
        UserRepositoryInterface $repository, // Resolved from container
        LoggerInterface $logger,              // Resolved from container
    ): Result {
        $logger->info('Creating user', ['email' => $command->email]);
        // ...
    }
}
```

The `ArgumentResolver` matches parameters by:

1. Type matching against the command object
2. Provided arguments (positional or named)
3. Container resolution by type-hint
4. Default values
5. Variadic collection of remaining arguments
