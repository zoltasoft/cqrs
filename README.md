# Zolta CQRS

[![PHP Version](https://img.shields.io/badge/PHP-8.2+-blue.svg)](https://php.net/)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-Level%206-brightgreen.svg)](https://phpstan.org/)
[![Laravel Version](https://img.shields.io/badge/Laravel-10+-red.svg)](https://laravel.com/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

**CQRS that fits in your stack, not the other way around.**

A complete application layer for PHP 8.2+: command/query buses with a decorator pipeline, `Result`/`Option` monads for predictable error handling, transactional orchestration with automatic event dispatching, repository abstractions with caching, and automatic handler discovery via PHP 8 attributes. No event sourcing required — but nothing stopping you if you want it.

```php
$result = $cqrs->dispatch(new CreateUserCommand(
    name: 'John',
    email: 'john@example.com',
    password: 'secret123',
));
// Validated → executed → events dispatched → transaction committed. One call.
```

---

## Why Zolta CQRS?

### The problem

Laravel gives you Eloquent, queues, and events — excellent infrastructure. But the **application architecture layer** between "HTTP request" and "database query" is left as a DIY exercise. Most teams end up with fat controllers, service classes that mix concerns, and event handling scattered across listeners. Testing is painful because business logic is tangled with framework code.

### What Zolta CQRS does differently

| Approach | How it works | Trade-off |
|----------|-------------|-----------|
| Ecotone | Full messaging framework with aggregates, projections, sagas | Heavyweight, steep learning curve, all-or-nothing |
| Broadway | Event sourcing toolkit | Requires event sourcing commitment |
| Spatie Event Sourcing | Laravel event sourcing | Event-sourcing only, no command/query separation |
| Tactician | Simple command bus | Command-only, no queries, no Result monads, no orchestration |
| **Zolta CQRS** | **Decorator-based buses + monads + Application Service orchestration** | **Pragmatic CQRS without event sourcing tax** |

Zolta CQRS occupies a pragmatic middle ground: you get clean command/query separation, type-safe results, automatic event dispatching, and transactional orchestration — without being forced into full event sourcing. Use as much or as little as your project needs.

### Who is this for?

- Teams who want **command/query separation** without rewriting their entire architecture
- Projects that need **multi-step transactional workflows** (registration, checkout, onboarding) with automatic rollback
- Developers who want **predictable error handling** without try/catch pyramids
- Applications that will **eventually need** event sourcing or queued commands, but not today

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

### The command bus decorator chain

Commands flow through a composable decorator pipeline — each layer adds one concern:

```
WorkerAwareRoutingCommandBus
├─ ShouldQueue? → QueuedCommandBus → ExecuteCommandJob (async)
└─ Sync path:
   EventDispatchingCommandBus          ← dispatches domain events post-success
   └─ ValidatingCommandBus             ← runs #[ValidatesCommand] validators
      └─ SynchronousCommandBus         ← resolves handler, injects dependencies, executes
```

Every decorator is optional. Need just sync dispatch? Use `SynchronousCommandBus` directly. Need validation without events? Stack only what you need. The `WorkerAwareRoutingCommandBus` detects worker context to prevent re-enqueue loops automatically.

### Result & Option monads

Commands return `Result`, queries return `Option` — no more guessing what a method returns:

```php
// Result: success or failure, always carrying domain events
$result = Result::success(value: $user->toArray(), events: $user->releaseEvents());
$result = Result::failure(new DomainException('Email taken'));

$result->isSuccess();           // bool
$result->getValue();            // mixed — the success value
$result->getError();            // Throwable — the failure
$result->getEvents();           // EventInterface[] — extracted post-commit

// Option: some, none, or error — null-safe query results
$option = Option::some(['id' => '123', 'name' => 'John']);
$option = Option::none();

$option->getOrElse(['fallback']);
$option->getOrFail(fn() => new NotFoundException('User not found'));
```

No more returning `null | array | false | throw` from service methods. The type tells you what happened.

### ApplicationService orchestration

Multi-command workflows with automatic transactions, result capturing, and response mapping:

```php
return $this->appService->transactional(function () use ($name, $email, $password) {
    // Each command result is captured with a key
    $this->appService->runAndCapture(new CreateUserCommand($name, $email, $password));

    // Reference earlier results via MapPlaceholder
    $this->appService->cqrs()->dispatch(new AssignRoleCommand(
        userId: new MapPlaceholder('createUser.id'),
        role: 'user',
    ));

    // Build response from captured values across commands
    return $this->appService->response([
        'id'    => 'createUser.id',
        'name'  => 'createUser.name',
        'email' => 'createUser.email',
    ]);
});
// If any command fails → auto-rollback. Events dispatch only on commit.
```

### Repository framework

Framework-agnostic repositories with 12 filter operators, relation loading, pagination, sorting, field selection, and namespace-scoped caching:

```php
class UserRepository extends EloquentBaseRepository
{
    protected array $allowedFilters = ['name', 'email', 'role_id'];
    protected array $allowedConstraintFields = ['account_id'];
    protected array $allowedRelations = ['role', 'permissions'];
    protected array $filterableRelations = ['role' => ['name']];

    public function forAccount(string $accountId, array $filters = []): iterable
    {
        $query = RepositoryQuery::fromOptions(['filters' => $filters])
            ->withConstraint(RepositoryConstraint::equals('account_id', $accountId));

        return $this->all($query);
    }

    // Built-in operators: eq, ne, gt, gte, lt, lte, like, not_like,
    //                     in, not_in, null, not_null, between
}
```

Mandatory constraints are created by application or infrastructure code, applied with
`AND` semantics before optional filters, and included in repository cache keys. Raw
constraint arrays are rejected, undeclared constraint fields fail closed, and a client
filter cannot replace a constrained field. Do not construct constraints from HTTP input.

Cache layer uses tagged keys with configurable TTL — `RepositoryCache` interface with Laravel, APCu, or null implementations.

### Message hydration

Automatic construction of Commands, Queries, and Value Objects from raw arrays — no manual `new` calls:

```php
$command = $cqrs->make(CreateUserCommand::class, [
    'name' => 'John',
    'email' => 'john@example.com',
    'password' => 'secret123',
]);
// Reflection-cached, type-aware, handles nested VOs via Forge integration
```

---

## Performance

Benchmarked on a real application (Laravel 12, PHP 8.3, SQLite):

| Component | Time (warm) |
|-----------|-------------|
| CommandBus dispatch overhead | < 1ms |
| QueryBus ask overhead | < 1ms |
| ApplicationService wrapping | < 2ms |
| Message hydration (cached class) | < 0.6ms |
| Event dispatching | < 1ms |
| **Total CQRS overhead per request** | **< 5ms** |

The dominant costs in any request are your application logic — database queries, bcrypt hashing, external API calls. The CQRS layer stays invisible.

---

## Features at a glance

| Feature | Details |
|---------|---------|
| **Command bus** | 5-layer decorator chain: sync → validating → event-dispatching → queued → worker-aware |
| **Query bus** | In-memory with automatic handler resolution and dependency injection |
| **Result monad** | `success(value, events)` / `failure(error, events)` with event accumulation |
| **Option monad** | `some(values)` / `none()` / `error(throwable)` — null-safe queries |
| **ApplicationService** | Transactional orchestration, capture store, placeholder resolution, response mapping |
| **Handler discovery** | `#[HandlesCommand]` · `#[HandlesQuery]` · `#[ValidatesCommand]` · `#[HandlesDomainEvent]` |
| **Argument resolution** | Container injection + command/query type matching + variadic support |
| **Message hydration** | Reflection-cached construction from arrays, nested VO support via Forge |
| **Repository** | Abstract base + Eloquent impl with 12 filter operators, caching, pagination, sorting |
| **Transactions** | Auto-commit on `Result::success`, auto-rollback on `Result::failure` |
| **Domain events** | Aggregates record → Results carry → bus dispatches post-commit |
| **Queue integration** | `ShouldQueue` marker → automatic defer via `ExecuteCommandJob` |
| **Framework agnostic** | PSR-11 core, Laravel adapter with 13 service providers |

---

## Part of the Zolta Ecosystem

Zolta CQRS is the **application layer** — it bridges domain logic and transport:

```
┌─────────────────────────────────────────────┐
│  zolta/http (Transport)                     │
│  Attribute-driven routing & response        │
├─────────────────────────────────────────────┤
│  zolta/cqrs (Application) ← you are here   │
│  Commands, queries, events, transactions    │
├─────────────────────────────────────────────┤
│  zolta/forge (Domain)                       │
│  Value Objects, rules, specs, entities      │
└─────────────────────────────────────────────┘
```

When used together: **HTTP** resolves the pipeline via attributes → **Forge** hydrates the command with validated VOs → **CQRS** dispatches through the bus, captures events, wraps transactions → **HTTP** transforms and returns the response. Sub-10ms package overhead for the entire vertical stack.

| Package | Layer | Link |
|---------|-------|------|
| zolta/forge | Domain | [`packages/forge`](../zolta-forge) |
| **zolta/cqrs** | **Application** | You are here |
| zolta/http | Transport | [`packages/http`](../zolta-http) |

---

## QA

```bash
composer run qa          # Full suite: lint + analyse + phpmd + rector + test
composer run test        # PHPUnit only
```

**61 tests, 103 assertions** covering Result/Option monads, command and query bus dispatch, validator chains, event dispatching, message hydration, and argument resolution.

---

## Documentation

Full documentation is available in the [`docs/`](./docs/) directory, organized for serving via Nuxt Content.

---

## License

[MIT](LICENSE) © 2026 Redouane Taleb
