---
title: Queries
description: Query bus and handler resolution for read operations.
navigation:
  title: Queries
  order: 2
---

# Queries

Queries represent **read operations** that retrieve data without changing state. They are executed through the query bus and return `Option` monads.

## QueryInterface

```php
namespace Zolta\Cqrs\Queries\Contracts;

interface QueryInterface
{
    // Marker interface
}
```

## Query base class

```php
namespace Zolta\Cqrs\Queries;

abstract class Query implements QueryInterface
{
    use Normalizable;

    public static function alias(): string;
}
```

The `alias()` method converts the class name to camelCase: `GetUserQuery` → `getUser`.

### Defining a query

```php
<?php

declare(strict_types=1);

namespace App\Application\Queries\GetUser;

use Zolta\Cqrs\Queries\Query;

class GetUserQuery extends Query
{
    public function __construct(
        public readonly string $userId,
    ) {}
}
```

## QueryBusInterface

```php
namespace Zolta\Cqrs\Queries\Contracts;

interface QueryBusInterface
{
    public function register(string $query, string $handler): void;
    public function ask(QueryInterface $query, ...$args): mixed;
}
```

## InMemoryQueryBus

The default query bus implementation:

```php
namespace Zolta\Cqrs\Queries;

class InMemoryQueryBus implements QueryBusInterface
{
    public function __construct(
        ContainerInterface $container,
        ?ContainerInterface $handlerLocator = null,
    );

    public function register(string $queryClass, string $handlerClass): void;
    public function ask(QueryInterface $query, ...$args): mixed;
}
```

- Resolves handlers from the container
- Uses `ArgumentResolver` to determine which method to call
- Caches handler selections and parameter metadata

## Handler discovery

Query handlers are discovered via `#[HandlesQuery]`:

```php
use Zolta\Cqrs\Attributes\HandlesQuery;

#[HandlesQuery(GetUserQuery::class)]
class GetUserHandler
{
    public function __construct(
        private readonly UserRepositoryInterface $repository,
    ) {}

    public function __invoke(GetUserQuery $query): Option
    {
        $user = $this->repository->findById($query->userId);

        if (!$user) {
            return Option::none();
        }

        return Option::some($user->toArray());
    }
}
```

### `#[HandlesQuery]` attribute

```php
#[Attribute(Attribute::TARGET_CLASS)]
class HandlesQuery
{
    public function __construct(
        public string $queryClass,
        public ?string $methodName = null,
    );
}
```

### Method resolution

1. `__invoke()` if callable
2. Method specified in `#[HandlesQuery]` attribute
3. Fallback: `handle()`

## Option monad

Query handlers return `Option` to represent presence or absence of data:

```php
namespace Zolta\Cqrs\Services;

abstract class Option
{
    // Static constructors
    public static function some(MessagePayloadInterface|array $values): Some;
    public static function none(): None;
    public static function error(Throwable $throwable): ErrorOption;
    public static function of(mixed $value, ?string $key = null): Option;

    // Query methods
    public function isSome(): bool;
    public function isNone(): bool;
    public function get(): array;
    public function getOrNull(): array;
    public function getOrElse(array $default): array;
    public function toArray(): array;
    public function fetch(string $key): mixed;

    // Unwrap with callbacks
    public function getOrFail(
        ?Closure $exceptionFactory = null,
        ?Closure $onSuccess = null,
    ): array;
}
```

### Option variants

| Variant | `isSome()` | `isNone()` | `get()` |
|---------|-----------|-----------|---------|
| `Some` | `true` | `false` | Returns data |
| `None` | `false` | `true` | Returns `[]` |
| `ErrorOption` | `false` | `true` | Throws stored error |

### Usage patterns

```php
// Wrap a value
$option = Option::some(['id' => '123', 'name' => 'John']);

// Wrap with a key
$option = Option::of($user->toArray(), 'user');
// → Some { 'user' => [...] }

// Empty result
$option = Option::none();

// Error result
$option = Option::error(new \RuntimeException('Database unavailable'));

// Access data
$data = $option->getOrNull();          // [] if none
$data = $option->getOrElse(['default']); // Fallback if none
$name = $option->fetch('name');        // Single key access

// Unwrap with error handling
$data = $option->getOrFail(
    exceptionFactory: fn() => new NotFoundException('User not found'),
    onSuccess: fn(array $data) => $data,
);
```

## Executing queries

### Via CqrsServiceInterface

```php
// With a query object
$result = $cqrs->ask(new GetUserQuery(userId: '123'));

// With a class name + data (auto-hydrated)
$result = $cqrs->ask(GetUserQuery::class, userId: '123');

// Via the generic run() method (auto-detects type)
$result = $cqrs->run(new GetUserQuery(userId: '123'));
```

### Via ApplicationService

```php
$result = $this->applicationService->runAndCapture(
    new GetUserQuery(userId: $userId),
);
// → Option (auto-captured to internal store under 'getUser' alias)
```

## Query with pagination

```php
class ListUsersQuery extends Query
{
    public function __construct(
        public readonly int $page = 1,
        public readonly int $perPage = 20,
        public readonly ?string $search = null,
    ) {}
}

#[HandlesQuery(ListUsersQuery::class)]
class ListUsersHandler
{
    public function __construct(
        private readonly UserRepositoryInterface $repository,
    ) {}

    public function __invoke(ListUsersQuery $query): Option
    {
        $options = new QueryOptions([
            'page' => $query->page,
            'limit' => $query->perPage,
            'filters' => $query->search
                ? ['name' => $query->search]
                : [],
        ]);

        $users = $this->repository->paginate($options);

        return Option::some([
            'users' => $users->items(),
            'total' => $users->total(),
            'page' => $query->page,
        ]);
    }
}
```

## Query with dependencies

Handlers receive container-resolved dependencies via `ArgumentResolver`:

```php
#[HandlesQuery(GetUserQuery::class)]
class GetUserHandler
{
    public function __invoke(
        GetUserQuery $query,
        UserRepositoryInterface $repository, // From container
        CacheService $cache,                  // From container
    ): Option {
        return $cache->remember("user:{$query->userId}", function () use ($query, $repository) {
            $user = $repository->findById($query->userId);
            return $user ? Option::some($user->toArray()) : Option::none();
        });
    }
}
```
