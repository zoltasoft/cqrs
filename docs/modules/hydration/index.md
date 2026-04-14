---
title: Hydration
description: Message hydration for automatic command, query, and value object construction.
navigation:
  title: Hydration
  order: 8
---

# Hydration

The hydration system enables automatic construction of Commands, Queries, and Value Objects from raw data arrays. This powers the `Cqrs::make()` method and class-name dispatching.

## MessageHydratorInterface

```php
namespace Zolta\Cqrs\Hydration;

interface MessageHydratorInterface
{
    public function hydrate(string|object $target, array $data = []): object;
}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$target` | `string\|object` | Class name or existing object to hydrate |
| `$data` | `array` | Key-value data to map to constructor parameters |

If `$target` is already an object, it is returned as-is.

## DefaultMessageHydrator

The built-in hydrator with three resolution strategies:

```php
namespace Zolta\Cqrs\Hydration;

class DefaultMessageHydrator implements MessageHydratorInterface
{
    public function hydrate(string|object $target, array $data = []): object;
}
```

### Resolution strategies

**1. Schema-based hydration**

If the target class defines a `schema()` method, the hydrator uses it to build nested structures:

```php
class CreateOrderCommand extends Command
{
    public function __construct(
        public readonly string $userId,
        public readonly OrderItems $items,
    ) {}

    public static function schema(): array
    {
        return [
            'items' => OrderItems::class,
        ];
    }
}
```

**2. Value Object hydration**

If the target extends `ValueObject` from zolta-forge, the hydrator uses `resolve()`:

```php
// Automatically hydrated via ValueObject::resolve()
$email = $hydrator->hydrate(Email::class, ['address' => 'john@example.com']);
```

**3. Constructor mapping (fallback)**

Maps `$data` keys to constructor parameters by name or position:

```php
class CreateUserCommand extends Command
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $password,
    ) {}
}

$command = $hydrator->hydrate(CreateUserCommand::class, [
    'name' => 'John',
    'email' => 'john@example.com',
    'password' => 'secret',
]);
```

### Performance

The hydrator uses `ReflectionCache` for constructor parameter metadata, ensuring near-zero overhead after the first hydration of each class.

## Usage via CqrsServiceInterface

### `make()` — Hydrate without dispatching

```php
$command = $cqrs->make(CreateUserCommand::class, [
    'name' => 'John',
    'email' => 'john@example.com',
    'password' => 'secret',
]);
// → CreateUserCommand instance (not dispatched)
```

### `dispatch()` with string — Hydrate and dispatch

```php
// Pass class name + data → auto-hydrated, then dispatched
$result = $cqrs->dispatch(CreateUserCommand::class, [
    'name' => 'John',
    'email' => 'john@example.com',
    'password' => 'secret',
]);
```

### `ask()` with string — Hydrate and execute query

```php
$result = $cqrs->ask(GetUserQuery::class, [
    'userId' => '123',
]);
```

## MessagePayloadInterface

The standard contract for extractable payloads:

```php
namespace Zolta\Cqrs\Contracts;

interface MessagePayloadInterface
{
    public function toArray(): array;
}
```

### ArrayMessagePayload

A simple array-based payload:

```php
namespace Zolta\Cqrs\Payload;

class ArrayMessagePayload implements MessagePayloadInterface, JsonSerializable
{
    public function __construct(private array $data);

    public function toArray(): array;
    public function jsonSerialize(): mixed;
}
```

## `#[CommandMapping]` attribute

Maps a command to a DTO for hydration routing:

```php
namespace Zolta\Cqrs\Attributes;

#[Attribute(Attribute::TARGET_CLASS)]
class CommandMapping
{
    public function __construct(
        public string $commandClass,
        public string $dtoClass,
    );
}
```

Usage:

```php
#[CommandMapping(
    commandClass: CreateUserCommand::class,
    dtoClass: CreateUserInput::class,
)]
class CreateUserHandler
{
    public function __invoke(CreateUserCommand $command): Result
    {
        // $command was hydrated from CreateUserInput DTO
    }
}
```

## `#[MapFrom]` attribute

Maps a property or parameter from a specific source key:

```php
namespace Zolta\Cqrs\Attributes;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class MapFrom
{
    public function __construct(
        public string $commandClass,
        public string $dtoClass,
    );
}
```

## Custom hydrator

Implement `MessageHydratorInterface` for custom hydration logic:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Hydration;

use Zolta\Cqrs\Hydration\MessageHydratorInterface;

class AutoMapperHydrator implements MessageHydratorInterface
{
    public function __construct(
        private readonly AutoMapperInterface $mapper,
    ) {}

    public function hydrate(string|object $target, array $data = []): object
    {
        if (is_object($target)) {
            return $target;
        }

        return $this->mapper->map($data, $target);
    }
}
```

Register in a service provider:

```php
$this->app->bind(
    MessageHydratorInterface::class,
    AutoMapperHydrator::class,
);
```
