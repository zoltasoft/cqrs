---
title: Application Service
description: Orchestration pipeline with transactions, capture store, and response mapping.
navigation:
  title: Application Service
  order: 4
---

# Application Service

The `ApplicationService` is the top-level orchestration layer. It coordinates multiple commands and queries within a single transaction, captures intermediate results, and builds structured responses.

## Constructor

```php
namespace Zolta\Cqrs\Services\Pipeline;

class ApplicationService
{
    public function __construct(
        private readonly CqrsServiceInterface $cqrsService,
        private readonly ?TransactionManagerInterface $transactionManager = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    );
}
```

## Core methods

### `transactional(callable $callback): mixed`

Wraps a callback in a database transaction with automatic commit/rollback:

```php
$result = $applicationService->transactional(function () {
    $this->runAndCapture(new CreateUserCommand(...));
    $this->runAndCapture(new AssignRoleCommand(...));

    return $this->response([
        'userId' => 'createUser.id',
        'role' => 'assignRole.name',
    ]);
});
```

**Transaction behavior:**

| Callback result | Action |
|----------------|--------|
| `Result::success()` | **Commit** |
| `Result::failure()` | **Rollback** |
| `Option::some()` | **Commit** |
| `Option::none()` | **Rollback** |
| Exception thrown | **Rollback** + re-throw |
| Any other value | **Commit** |

If no `TransactionManagerInterface` is bound, the callback executes without a transaction wrapper.

### `runAndCapture(CommandInterface|QueryInterface|string $message, ...$args): Result|Option`

Dispatches a command or query and captures the result in the internal store:

```php
// Dispatch command — captured under 'createUser' alias
$result = $this->runAndCapture(new CreateUserCommand(
    name: 'John',
    email: 'john@example.com',
    password: 'secret',
));

// Dispatch query — captured under 'getUser' alias
$option = $this->runAndCapture(new GetUserQuery(userId: '123'));
```

**Capture key:** The fully qualified class name is used as the storage alias (e.g., `App\Application\Commands\CreateUser\CreateUserCommand`).

**Auto-detection:**
- `CommandInterface` → dispatched via `CommandBusInterface` → returns `Result`
- `QueryInterface` → dispatched via `QueryBusInterface` → returns `Option`
- On exception → returns `Result::failure()` or `Option::error()`

### `cqrs(): CqrsServiceInterface`

Returns a `CqrsProxy` that automatically resolves `MapPlaceholder` values from the capture store and auto-captures results:

```php
// Use MapPlaceholder to reference captured data
$this->cqrs()->dispatch(new AssignRoleCommand(
    userId: new MapPlaceholder('createUser.id'),
    role: 'admin',
));
```

### `capture(array $data, ?string $alias = null): void`

Manually add data to the capture store:

```php
$this->capture(['timestamp' => time()], 'metadata');
```

### `captureMessage(CommandInterface|QueryInterface|string $message, mixed $envelope): void`

Extracts payload from a Result/Option/MessagePayload and stores it:

```php
$this->captureMessage($command, $result);
// Payload extracted from Result/Option and stored under command's alias
```

### `getCaptured(): array`

Returns all captured data:

```php
$store = $this->getCaptured();
// ['createUser' => ['id' => '...', 'name' => '...'], ...]
```

### `clearCaptured(): void`

Clears the entire capture store.

## Response mapping

### `get(array|string $map): mixed`

Resolves a dot-path or nested map from captured data:

```php
// Simple dot-path
$userId = $this->get('createUser.id');

// Object property access
$userName = $this->get('createUser.name.value');

// Nested map
$data = $this->get([
    'id' => 'createUser.id',
    'email' => 'createUser.email.address',
]);
```

### `buildArray(array|string $map, mixed $context = null, mixed $rootContext = null): mixed`

Builds a structured array from dot-path mappings with support for collections:

```php
// Simple mapping
$data = $this->buildArray([
    'userId' => 'createUser.id',
    'email' => 'createUser.email',
]);

// Collection mapping (note the [] syntax)
$data = $this->buildArray([
    'permissions' => [
        '[permissions]' => [
            'id' => 'permissions.id',
            'name' => 'permissions.name',
        ],
    ],
]);
```

### `response(array $map, ?string $responseDto = null): array|object`

Builds a response from the capture store:

```php
// As array
$response = $this->response([
    'id' => 'createUser.id',
    'name' => 'createUser.name',
    'email' => 'createUser.email',
]);

// As DTO
$response = $this->response(
    [
        'id' => 'createUser.id',
        'name' => 'createUser.name',
        'email' => 'createUser.email',
    ],
    UserResponse::class,
);
// → UserResponse { id: '...', name: '...', email: '...' }
```

## Event dispatching

### `dispatchEvents(array $events): void`

Dispatches domain events via the registered `EventDispatcherInterface`:

```php
$this->dispatchEvents([
    new UserCreatedEvent($userId, $email),
    new WelcomeBonusGrantedEvent($userId, 100),
]);
```

## MapPlaceholder

Signals the CqrsProxy to resolve a value from the capture store before dispatch:

```php
namespace Zolta\Cqrs\Services;

class MapPlaceholder
{
    public function __construct(private string $path);
    public function value(): string;
}
```

Usage in chained commands:

```php
$this->runAndCapture(new CreateUserCommand(
    name: 'John',
    email: 'john@example.com',
    password: 'secret',
));

// Use the captured userId in the next command
$this->cqrs()->dispatch(new CreateProfileCommand(
    userId: new MapPlaceholder('createUser.id'),
    bio: 'Hello world',
));
```

## CqrsProxy

The proxy wraps the core CQRS service to enable automatic placeholder resolution and result capture:

```php
namespace Zolta\Cqrs\Services;

class CqrsProxy implements CqrsServiceInterface
{
    public function __construct(
        private CqrsServiceInterface $cqrsService,
        private ApplicationService $applicationService,
    );

    public function dispatch(CommandInterface|string $commandOrClass, ...$args): mixed;
    public function ask(QueryInterface|string $queryOrClass, ...$args): mixed;
    public function run(CommandInterface|QueryInterface|string $message, ...$args): mixed;
    public function make(string|object $class, array $data = []): mixed;
}
```

**Before dispatch:** Inspects all arguments for `MapPlaceholder` values and resolves them from the capture store.

**After dispatch:** Auto-captures the result using `captureMessage()`.

## Complete example

```php
<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Commands\CreateUser\CreateUserCommand;
use App\Application\Commands\AssignRole\AssignRoleCommand;
use App\Application\Queries\GetUser\GetUserQuery;
use App\Application\DTO\UserResponse;
use Zolta\Cqrs\Services\MapPlaceholder;
use Zolta\Cqrs\Services\Pipeline\ApplicationService;

class UserRegistrationService
{
    public function __construct(
        private readonly ApplicationService $applicationService,
    ) {}

    public function register(string $name, string $email, string $password): UserResponse
    {
        return $this->applicationService->transactional(function () use ($name, $email, $password) {
            // Step 1: Create the user
            $this->applicationService->runAndCapture(new CreateUserCommand(
                name: $name,
                email: $email,
                password: $password,
            ));

            // Step 2: Assign default role (uses captured userId)
            $this->applicationService->cqrs()->dispatch(new AssignRoleCommand(
                userId: new MapPlaceholder('createUser.id'),
                role: 'user',
            ));

            // Step 3: Build response from captured data
            return $this->applicationService->response(
                [
                    'id' => 'createUser.id',
                    'name' => 'createUser.name',
                    'email' => 'createUser.email',
                    'role' => 'assignRole.name',
                ],
                UserResponse::class,
            );
        });
    }
}
```

If any step fails, the entire transaction is rolled back automatically.
