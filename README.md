# Zolta CQRS

[![PHP Version](https://img.shields.io/badge/PHP-8.2+-blue.svg)](https://php.net/)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-Level%206-brightgreen.svg)](https://phpstan.org/)
[![Laravel Version](https://img.shields.io/badge/Laravel-10+-red.svg)](https://laravel.com/)
[![License](https://img.shields.io/badge/License-Proprietary-orange.svg)](LICENSE)

**Zolta CQRS** is the application layer of the Zolta framework. It provides a complete CQRS implementation for PHP 8.2+ with automatic handler discovery, a decorator-based command bus pipeline, Result/Option monads, repository abstractions with caching, transaction management, and domain event dispatching.

---

## Features

- **Command Bus Pipeline** — Decorator chain: Sync → Validating → EventDispatching → Queued → WorkerAwareRouting
- **Query Bus** — In-memory query bus with automatic handler resolution
- **Result/Option Monads** — Type-safe success/failure/none handling for commands and queries
- **ApplicationService** — Orchestration pipeline with transactions, capture store, and response mapping
- **Transaction Management** — Automatic commit/rollback based on Result/Option outcomes
- **Domain Event Dispatching** — Events recorded by aggregates, extracted from Results, dispatched post-commit
- **Repository Abstraction** — Filtering, sorting, pagination, relation loading, and namespace-scoped caching
- **Attribute-Based Discovery** — `#[HandlesCommand]`, `#[HandlesQuery]`, `#[ValidatesCommand]`, `#[HandlesDomainEvent]`
- **Automatic Argument Resolution** — Handler methods receive container-resolved dependencies alongside commands
- **Message Hydration** — Automatic construction of Commands/Queries/VOs from raw data
- **Queue Integration** — Commands marked `ShouldQueue` are automatically deferred to Laravel queues
- **Framework Agnostic** — Core is PSR-compatible; Laravel adapter provided out of the box

---

## Install

```bash
composer require zolta/cqrs
```

Laravel auto-discovers the service provider. No manual registration needed.

### Publish configuration

```bash
php artisan vendor:publish --tag=zolta-cqrs-config
```

This creates `config/zolta.php` with paths to scan for handlers:

```php
return [
    'commands' => [app_path('Application/Commands')],
    'queries'  => [app_path('Application/Queries')],
    'events'   => [app_path('Infrastructure/Events')],
];
```

---

## Quick Start

### 1. Define a command

```php
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

### 2. Create a handler

```php
use Zolta\Cqrs\Attributes\HandlesCommand;
use Zolta\Cqrs\Services\Result;

#[HandlesCommand(CreateUserCommand::class)]
class CreateUserHandler
{
    public function __construct(
        private readonly UserRepositoryInterface $repository,
    ) {}

    public function __invoke(CreateUserCommand $command): Result
    {
        $user = User::create(
            id: UserId::generate(),
            name: Username::resolve(['value' => $command->name]),
            email: Email::resolve(['address' => $command->email]),
            password: HashedPassword::fromPlaintext($command->password),
        );

        $this->repository->save($user);

        return Result::success(
            value: $user->toArray(),
            events: $user->releaseEvents(),
        );
    }
}
```

### 3. Add validation (optional)

```php
use Zolta\Cqrs\Attributes\ValidatesCommand;

#[ValidatesCommand(CreateUserCommand::class)]
class CreateUserValidator
{
    public function validate(CreateUserCommand $command): void
    {
        if ($this->repository->findByEmail($command->email)) {
            throw new ValidationException(['email' => 'Already registered.']);
        }
    }
}
```

### 4. Dispatch

```php
$result = $cqrs->dispatch(new CreateUserCommand(
    name: 'John',
    email: 'john@example.com',
    password: 'secret123',
));

$userId = $result->getValue()['id'];
```

### 5. Query data

```php
use Zolta\Cqrs\Queries\Query;
use Zolta\Cqrs\Attributes\HandlesQuery;
use Zolta\Cqrs\Services\Option;

class GetUserQuery extends Query
{
    public function __construct(public readonly string $userId) {}
}

#[HandlesQuery(GetUserQuery::class)]
class GetUserHandler
{
    public function __invoke(GetUserQuery $query): Option
    {
        $user = $this->repository->findById($query->userId);
        return $user ? Option::some($user->toArray()) : Option::none();
    }
}

$option = $cqrs->ask(new GetUserQuery(userId: '123'));
$data = $option->getOrFail(fn() => new NotFoundException('User not found'));
```

### 6. Orchestrate with ApplicationService

```php
class RegistrationService
{
    public function __construct(private ApplicationService $appService) {}

    public function register(string $name, string $email, string $password): array
    {
        return $this->appService->transactional(function () use ($name, $email, $password) {
            $this->appService->runAndCapture(new CreateUserCommand($name, $email, $password));

            $this->appService->cqrs()->dispatch(new AssignRoleCommand(
                userId: new MapPlaceholder('createUser.id'),
                role: 'user',
            ));

            return $this->appService->response([
                'id' => 'createUser.id',
                'name' => 'createUser.name',
                'email' => 'createUser.email',
            ]);
        });
    }
}
```

---

## Architecture

```
ApplicationService (orchestration + transactions)
    └─ CqrsProxy (placeholder resolution + auto-capture)
        └─ Cqrs Service (dispatch/ask/run/make)
            ├─ COMMAND PATH:
            │   WorkerAwareRoutingCommandBus
            │   ├─ Sync: EventDispatching → Validating → Synchronous → Handler
            │   └─ Async: QueuedCommandBus → ExecuteCommandJob
            │
            └─ QUERY PATH:
                InMemoryQueryBus → Handler

Events:     EventDispatcherInterface → LaravelEventDispatcher
Repository: AbstractRepository → EloquentBaseRepository (with cache)
Transactions: TransactionManagerInterface → LaravelTransactionManager
```

---

## Ecosystem

| Package | Layer | Description |
|---------|-------|-------------|
| [zolta/forge](../zolta-forge) | Domain | Value Objects, Entities, Rules, Specifications, Policies |
| **zolta/cqrs** | **Application** | **Commands, Queries, Events, Repositories, Transactions** |
| [zolta/http](../zolta-http) | API | Routing, Request/Response, Authorization |

---

## QA

```bash
composer run lint        # Pint code style
composer run analyse     # PHPStan Level 6
composer run md          # PHPMD
composer run rector      # Rector
composer run test        # PHPUnit
composer run qa          # All of the above
```

---

## Documentation

Full documentation is available in the [`docs/`](./docs/) directory, organized for serving via Nuxt Content.

---

## License

**Proprietary — © 2025 Redouane Taleb**
Unauthorized copying, modification, or distribution is prohibited.
