---
title: Advanced
description: Internals, extension points, and advanced patterns for Zolta CQRS.
navigation:
  title: Advanced
  order: 11
---

# Advanced

## Command bus factory

The `CommandBusFactory` assembles the complete decorator chain:

```php
namespace Zolta\Cqrs\Factories;

class CommandBusFactory
{
    public static function create(
        ContainerInterface $container,
        array $commandMap,
        ?ContainerInterface $handlerLocator = null,
    ): CommandBusInterface;
}
```

The factory builds this chain:

```
1. SynchronousCommandBus      ← Handler execution
2. ValidatingCommandBus       ← Wraps #1
3. EventDispatchingCommandBus ← Wraps #2
4. QueuedCommandBus           ← Wraps #2 (separate branch)
5. WorkerAwareRoutingCommandBus ← Routes between #3 and #4
```

## Handler scanning

### CommandMapFactory

Scans PHP files for `#[HandlesCommand]` and `#[ValidatesCommand]` attributes:

```php
namespace Zolta\Cqrs\Factories;

class CommandMapFactory
{
    public static function create(
        array $files,
        callable $classLoader,
        ?callable $singletonRegistrar = null,
    ): array;
}
```

Returns a map of:

```php
[
    CreateUserCommand::class => [
        'handler' => CreateUserHandler::class,
        'validator' => CreateUserValidator::class,
    ],
    // ...
]
```

### QueryMapFactory

```php
class QueryMapFactory
{
    public static function create(array $classes): array;
}
```

### EventMapFactory

```php
class EventMapFactory
{
    public static function create(
        array $files,
        callable $classLoader,
        ?callable $singletonRegistrar = null,
    ): array;
}
```

## Laravel service provider chain

The `ZoltaCqrsServiceProvider` orchestrates sub-providers in this order:

| Provider | Responsibility |
|----------|---------------|
| `LaravelBridgeServiceProvider` | Framework integration bindings |
| `HydrationServiceProvider` | `MessageHydratorInterface` binding |
| `CqrsServiceProvider` | `CqrsServiceInterface` + `ApplicationService` |
| `QueryBusServiceProvider` | `QueryBusInterface` binding |
| `CommandBusServiceProvider` | `CommandBusInterface` via factory |
| `EventServiceProvider` | `EventDispatcherInterface` binding |
| `CommandMapServiceProvider` | Scans for `#[HandlesCommand]` |
| `QueryMapServiceProvider` | Scans for `#[HandlesQuery]` |
| `EventMapServiceProvider` | Scans for `#[HandlesDomainEvent]` |
| `QueryOptionsServiceProvider` | `QueryOptions` helpers |
| `ZoltaCommandServiceProvider` | Artisan commands |

## Cqrs service

The core service that routes dispatches:

```php
namespace Zolta\Cqrs\Services;

class Cqrs implements CqrsServiceInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private MessageHydratorInterface $messageHydrator,
    );

    public function dispatch(CommandInterface|string $commandOrClass, ...$args): mixed;
    public function ask(QueryInterface|string $queryOrClass, ...$args): mixed;
    public function run(CommandInterface|QueryInterface|string $message, ...$args): mixed;
    public function make(string|object $class, array $data = []): mixed;
}
```

### String-based dispatching

```php
// Pass class name → auto-hydrated via MessageHydrator
$result = $cqrs->dispatch(CreateUserCommand::class, [
    'name' => 'John',
    'email' => 'john@example.com',
    'password' => 'secret',
]);

// Equivalent to:
$command = $cqrs->make(CreateUserCommand::class, $data);
$result = $cqrs->dispatch($command);
```

### Generic `run()` method

The `run()` method auto-detects the message type:

```php
// Dispatches as command
$cqrs->run(new CreateUserCommand(...));

// Dispatches as query
$cqrs->run(new GetUserQuery(...));

// Auto-detects from class name
$cqrs->run(CreateUserCommand::class, $data);
```

## ApplicationService capture store

The capture store is a key-value map populated by `runAndCapture()`:

```php
// After running CreateUserCommand:
// Store: ['App\...\CreateUserCommand' => ['id' => '123', 'name' => 'John', ...]]

// After running GetUserQuery:
// Store: ['App\...\GetUserQuery' => ['id' => '123', 'name' => 'John', ...]]
```

### Payload extraction

`captureMessage()` extracts data from different result types:

| Result type | Extraction |
|-------------|-----------|
| `Result::success($value)` | `$value` (or `toArray()` if object) |
| `Result::failure($error)` | Error captured, returns failure |
| `Option::some($data)` | `$data` array |
| `Option::none()` | Alias deleted from store |
| `MessagePayloadInterface` | `->toArray()` |
| `array` | Used directly |

### Dot-path resolution

The `get()` and `buildArray()` methods support nested path access:

```php
// Simple property
$this->get('createUser.id');

// Nested object properties
$this->get('createUser.email.address');

// Getter methods
$this->get('createUser.displayName'); // Calls getDisplayName()

// Array key access
$this->get('createUser.permissions.0.name');
```

### Collection mapping

The `buildArray()` method supports collection iteration with `[]` syntax:

```php
$response = $this->buildArray([
    'userId' => 'createUser.id',
    'permissions' => [
        '[listPermissions]' => [
            'id' => 'listPermissions.id',
            'name' => 'listPermissions.name',
            'level' => 'listPermissions.level',
        ],
    ],
]);
```

The `[listPermissions]` key signals that the captured `listPermissions` data is a collection, and the nested map is applied to each item.

## Worker detection

The `WorkerAwareRoutingCommandBus` checks if it's running inside a queue worker:

```php
// Set by ExecuteCommandJob before handling
app()->instance('zolta.commandbus.in_worker', true);

// Checked by WorkerAwareRoutingCommandBus
$inWorker = app('zolta.commandbus.in_worker');
```

This prevents queued commands from being re-queued when processed by a worker, ensuring they execute synchronously within the worker process.

## Custom command bus decorator

Create middleware that wraps the command bus:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\CommandBus;

use Zolta\Cqrs\Commands\Contracts\CommandBusInterface;
use Zolta\Cqrs\Commands\Contracts\CommandInterface;

class LoggingCommandBus implements CommandBusInterface
{
    public function __construct(
        private readonly CommandBusInterface $decorated,
        private readonly LoggerInterface $logger,
    ) {}

    public function dispatch(CommandInterface $command, ...$args): mixed
    {
        $this->logger->info('Dispatching command', [
            'command' => get_class($command),
            'data' => $command->toArray(),
        ]);

        $start = microtime(true);
        $result = $this->decorated->dispatch($command, ...$args);
        $duration = microtime(true) - $start;

        $this->logger->info('Command completed', [
            'command' => get_class($command),
            'duration_ms' => round($duration * 1000, 2),
        ]);

        return $result;
    }

    public function register(string $command, object|string $handler): void
    {
        $this->decorated->register($command, $handler);
    }
}
```

## Extending the Eloquent repository

### Custom filter operators

```php
class OrderRepository extends EloquentBaseRepository
{
    protected array $filterOperators = [
        'status' => 'eq',
        'total' => 'gte',
        'created_at' => 'date_range',
        'customer_name' => 'like',
    ];

    protected function applyFilter(
        Builder $builder,
        string $key,
        mixed $value,
        QueryDefinition $queryDefinition,
    ): void {
        if ($key === 'date_range' && is_array($value)) {
            $builder->whereBetween('created_at', [
                $value['from'],
                $value['to'],
            ]);
            return;
        }

        parent::applyFilter($builder, $key, $value, $queryDefinition);
    }

    protected function modelClass(): string
    {
        return OrderModel::class;
    }
}
```

### Relation-scoped filtering

```php
protected function queryDefinition(): QueryDefinition
{
    return new QueryDefinition(
        allowedIncludes: ['customer', 'items', 'payments'],
        allowedFilters: ['status', 'total', 'created_at'],
        relationFilters: [
            'customer' => ['name', 'email'],  // Only these fields
            'items' => null,                   // All fields allowed
        ],
    );
}

// Usage:
$options = new QueryOptions([
    'filters' => [
        'status' => 'completed',
        'customer.name' => 'John',  // Filters on relation
    ],
    'includes' => ['customer', 'items'],
]);
```
