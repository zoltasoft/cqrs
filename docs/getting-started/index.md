---
title: Getting Started
description: Installation and first steps with Zolta CQRS.
navigation:
  title: Getting Started
  order: 1
---

# Getting Started

## Requirements

- PHP 8.2 or higher
- Composer 2.x
- Laravel 10+ (for the Laravel adapter)
- `zolta/forge` (domain layer dependency)

## Installation

```bash
composer require zolta/cqrs
```

The package auto-discovers the Laravel service provider via Composer's `extra.laravel` metadata. No manual registration is needed.

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=zolta-cqrs-config
```

This creates `config/zolta.php`. New applications should nest CQRS settings under `cqrs`:

```php
return [
    'cqrs' => [
        // Paths to scan for command handlers and validators
        'commands' => [
            app_path('Application/Commands'),
        ],

        // Paths to scan for query handlers
        'queries' => [
            app_path('Application/Queries'),
        ],

        // Paths to scan for infrastructure event listeners
        'infrastructure_events' => [
            app_path('Infrastructure/Events'),
        ],
    ],
];
```

Existing top-level CQRS settings, such as `zolta.commands` and `zolta.cache`, remain supported for backwards compatibility. New configuration should use `zolta.cqrs.*`.

## Project structure

A recommended DDD project structure with Zolta CQRS:

```
app/
├── Domain/                     # zolta-forge layer
│   ├── Aggregates/
│   │   └── User.php
│   ├── ValueObjects/
│   │   ├── Email.php
│   │   └── UserId.php
│   ├── Events/
│   │   └── UserCreatedEvent.php
│   └── Repositories/
│       └── UserRepositoryInterface.php
│
├── Application/                # zolta-cqrs layer
│   ├── Commands/
│   │   ├── CreateUser/
│   │   │   ├── CreateUserCommand.php
│   │   │   ├── CreateUserHandler.php
│   │   │   └── CreateUserValidator.php
│   │   └── UpdateUser/
│   │       ├── UpdateUserCommand.php
│   │       └── UpdateUserHandler.php
│   ├── Queries/
│   │   ├── GetUser/
│   │   │   ├── GetUserQuery.php
│   │   │   └── GetUserHandler.php
│   │   └── ListUsers/
│   │       ├── ListUsersQuery.php
│   │       └── ListUsersHandler.php
│   └── Services/
│       └── UserRegistrationService.php
│
├── Infrastructure/             # Framework adapters
│   ├── Repositories/
│   │   └── EloquentUserRepository.php
│   └── Events/
│       └── UserCreatedListener.php
│
└── Http/                       # zolta-http layer
    └── Controllers/
        └── UserController.php
```

## Your first command

### 1. Define the command

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

### 2. Create the handler

```php
<?php

declare(strict_types=1);

namespace App\Application\Commands\CreateUser;

use App\Domain\Aggregates\User;
use App\Domain\Repositories\UserRepositoryInterface;
use App\Domain\ValueObjects\Email;
use App\Domain\ValueObjects\HashedPassword;
use App\Domain\ValueObjects\UserId;
use App\Domain\ValueObjects\Username;
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

### 3. Dispatch the command

```php
use App\Application\Commands\CreateUser\CreateUserCommand;

// Via dependency injection
public function store(CqrsServiceInterface $cqrs)
{
    $result = $cqrs->dispatch(new CreateUserCommand(
        name: 'John Doe',
        email: 'john@example.com',
        password: 'secret123',
    ));
}
```

## Your first query

### 1. Define the query

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

### 2. Create the handler

```php
<?php

declare(strict_types=1);

namespace App\Application\Queries\GetUser;

use App\Domain\Repositories\UserRepositoryInterface;
use Zolta\Cqrs\Attributes\HandlesQuery;
use Zolta\Cqrs\Services\Option;

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

### 3. Execute the query

```php
use App\Application\Queries\GetUser\GetUserQuery;

$result = $cqrs->ask(new GetUserQuery(userId: '123'));
```
