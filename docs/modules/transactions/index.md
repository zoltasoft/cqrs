---
title: Transactions
description: Database transaction management with automatic commit and rollback.
navigation:
  title: Transactions
  order: 7
---

# Transactions

Zolta CQRS provides a `TransactionManagerInterface` for framework-agnostic transaction management. The `ApplicationService` uses it to automatically commit or rollback based on operation outcomes.

## TransactionManagerInterface

```php
namespace Zolta\Cqrs\Contracts;

interface TransactionManagerInterface
{
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
    public function runInTransaction(callable $callback): mixed;
}
```

| Method | Description |
|--------|-------------|
| `begin()` | Starts a new database transaction |
| `commit()` | Commits the current transaction |
| `rollback()` | Rolls back the current transaction |
| `runInTransaction()` | Executes callback within a transaction (commit on success, rollback on exception) |

## LaravelTransactionManager

The Laravel implementation using `DB` facade:

```php
namespace Zolta\Cqrs\Adapters\Laravel\Database;

use Illuminate\Support\Facades\DB;

class LaravelTransactionManager implements TransactionManagerInterface
{
    public function begin(): void
    {
        DB::beginTransaction();
    }

    public function commit(): void
    {
        DB::commit();
    }

    public function rollback(): void
    {
        DB::rollBack();
    }

    public function runInTransaction(callable $callback): mixed
    {
        return DB::transaction(Closure::fromCallable($callback));
    }
}
```

## Service provider binding

The `ZoltaCqrsServiceProvider` binds the interface automatically:

```php
$this->app->bind(
    TransactionManagerInterface::class,
    LaravelTransactionManager::class,
);
```

## ApplicationService integration

The `transactional()` method uses the transaction manager with intelligent commit/rollback:

```php
public function transactional(callable $callback): mixed
```

### Decision logic

```
Execute callback
    │
    ├─ Exception thrown?
    │   └─ ROLLBACK → re-throw exception
    │
    ├─ Result returned?
    │   ├─ isFailure() → ROLLBACK → return Result
    │   └─ isSuccess() → COMMIT → return Result
    │
    ├─ Option returned?
    │   ├─ isNone() → ROLLBACK → return Option
    │   └─ isSome() → COMMIT → return Option
    │
    └─ Other value?
        └─ COMMIT → return value
```

### Example

```php
$result = $applicationService->transactional(function () {
    // Step 1: Create user
    $userResult = $this->runAndCapture(new CreateUserCommand(
        name: 'John',
        email: 'john@example.com',
        password: 'secret',
    ));

    // Early exit on failure → triggers rollback
    if ($userResult->isFailure()) {
        return $userResult;
    }

    // Step 2: Assign role
    $roleResult = $this->runAndCapture(new AssignRoleCommand(
        userId: new MapPlaceholder('createUser.id'),
        role: 'user',
    ));

    if ($roleResult->isFailure()) {
        return $roleResult; // Rollback both operations
    }

    // Success → commit both operations
    return $this->response([
        'userId' => 'createUser.id',
        'role' => 'assignRole.name',
    ]);
});
```

## Graceful degradation

If no `TransactionManagerInterface` is bound (e.g., the constructor receives `null`), `transactional()` executes the callback **without** a transaction wrapper. This enables usage in environments without database transactions (e.g., testing, event sourcing).

```php
// No transaction manager → callback runs directly
$applicationService = new ApplicationService(
    cqrsService: $cqrs,
    transactionManager: null, // No transactions
);

$result = $applicationService->transactional(fn() => /* runs without TX */);
```

## Standalone usage

You can use `TransactionManagerInterface` directly:

```php
public function __construct(
    private readonly TransactionManagerInterface $tx,
) {}

public function execute(): void
{
    $this->tx->begin();

    try {
        $this->doSomething();
        $this->doSomethingElse();

        $this->tx->commit();
    } catch (\Throwable $e) {
        $this->tx->rollback();
        throw $e;
    }
}

// Or simpler:
public function execute(): mixed
{
    return $this->tx->runInTransaction(function () {
        $this->doSomething();
        $this->doSomethingElse();

        return $result;
    });
}
```

## Custom transaction manager

Implement the interface for other frameworks or databases:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use Zolta\Cqrs\Contracts\TransactionManagerInterface;

class DoctrineTransactionManager implements TransactionManagerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function begin(): void
    {
        $this->em->getConnection()->beginTransaction();
    }

    public function commit(): void
    {
        $this->em->flush();
        $this->em->getConnection()->commit();
    }

    public function rollback(): void
    {
        $this->em->getConnection()->rollBack();
        $this->em->clear();
    }

    public function runInTransaction(callable $callback): mixed
    {
        return $this->em->wrapInTransaction($callback);
    }
}
```

Register in your service provider:

```php
$this->app->bind(
    TransactionManagerInterface::class,
    DoctrineTransactionManager::class,
);
```
