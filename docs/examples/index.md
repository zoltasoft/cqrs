---
title: Examples
description: Real-world patterns and usage examples for Zolta CQRS.
navigation:
  title: Examples
  order: 10
---

# Examples

## User registration flow

A complete example orchestrating multiple commands within a transaction.

### Commands

```php
// CreateUserCommand
class CreateUserCommand extends Command
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $password,
    ) {}
}

// AssignPermissionCommand
class AssignPermissionCommand extends Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $permission,
    ) {}
}
```

### Handlers

```php
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

#[HandlesCommand(AssignPermissionCommand::class)]
class AssignPermissionHandler
{
    public function __construct(
        private readonly PermissionRepositoryInterface $repository,
    ) {}

    public function __invoke(AssignPermissionCommand $command): Result
    {
        $this->repository->assign($command->userId, $command->permission);

        return Result::success([
            'userId' => $command->userId,
            'permission' => $command->permission,
        ]);
    }
}
```

### Validator

```php
#[ValidatesCommand(CreateUserCommand::class)]
class CreateUserValidator
{
    public function __construct(
        private readonly UserRepositoryInterface $repository,
    ) {}

    public function validate(CreateUserCommand $command): void
    {
        $errors = [];

        if (empty($command->name)) {
            $errors['name'] = 'Name is required.';
        }

        if ($this->repository->findByEmail($command->email)) {
            $errors['email'] = 'Email already registered.';
        }

        if (strlen($command->password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }
}
```

### Application service

```php
class UserRegistrationService
{
    public function __construct(
        private readonly ApplicationService $appService,
    ) {}

    public function register(string $name, string $email, string $password): array
    {
        return $this->appService->transactional(function () use ($name, $email, $password) {
            // Create user
            $this->appService->runAndCapture(new CreateUserCommand(
                name: $name,
                email: $email,
                password: $password,
            ));

            // Assign default permissions
            $this->appService->cqrs()->dispatch(new AssignPermissionCommand(
                userId: new MapPlaceholder('createUser.id'),
                permission: 'user.read',
            ));

            $this->appService->cqrs()->dispatch(new AssignPermissionCommand(
                userId: new MapPlaceholder('createUser.id'),
                permission: 'profile.edit',
            ));

            // Build response
            return $this->appService->response([
                'id' => 'createUser.id',
                'name' => 'createUser.name',
                'email' => 'createUser.email',
            ]);
        });
    }
}
```

## Query with caching

### Query and handler

```php
class GetUserWithRolesQuery extends Query
{
    public function __construct(
        public readonly string $userId,
    ) {}
}

#[HandlesQuery(GetUserWithRolesQuery::class)]
class GetUserWithRolesHandler
{
    public function __construct(
        private readonly UserRepositoryInterface $repository,
    ) {}

    public function __invoke(GetUserWithRolesQuery $query): Option
    {
        $user = $this->repository->show(
            $query->userId,
            include: ['roles', 'permissions'],
        );

        if (!$user) {
            return Option::none();
        }

        return Option::some([
            'user' => $user->toArray(),
            'roles' => $user->roles->toArray(),
            'permissions' => $user->permissions->toArray(),
        ]);
    }
}
```

### Repository with caching

```php
class EloquentUserRepository extends EloquentBaseRepository implements UserRepositoryInterface
{
    protected bool $enableReadCaching = true;
    protected int $cacheTtlMinutes = 15;
    protected array $allowedFilters = ['name', 'email', 'status', 'role'];
    protected array $allowedRelations = ['roles', 'permissions', 'profile'];

    protected function modelClass(): string
    {
        return UserModel::class;
    }

    protected function queryDefinition(): QueryDefinition
    {
        return new QueryDefinition(
            allowedIncludes: ['roles', 'permissions', 'profile'],
            allowedFilters: ['name', 'email', 'status'],
            relationFilters: ['roles' => ['name']],
            operators: ['name' => 'like'],
        );
    }
}
```

## Async command processing

### Queueable command

```php
use Zolta\Cqrs\Commands\Interfaces\ShouldQueue;

class SendWelcomeEmailCommand extends Command implements ShouldQueue
{
    public function __construct(
        public readonly string $userId,
        public readonly string $email,
        public readonly string $name,
    ) {}
}

#[HandlesCommand(SendWelcomeEmailCommand::class)]
class SendWelcomeEmailHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
    ) {}

    public function __invoke(SendWelcomeEmailCommand $command): Result
    {
        $this->mailer->send(
            to: $command->email,
            template: 'welcome',
            data: ['name' => $command->name],
        );

        return Result::success();
    }
}
```

### Dispatching

```php
// This command is automatically queued
$cqrs->dispatch(new SendWelcomeEmailCommand(
    userId: $userId,
    email: $email,
    name: $name,
));
// Returns true immediately (fire-and-forget)
```

## Domain event chain

### Events

```php
final readonly class UserCreatedEvent implements EventInterface
{
    public function __construct(
        public string $userId,
        public string $email,
    ) {}

    public function eventName(): string
    {
        return 'user.created';
    }
}
```

### Listeners

```php
#[HandlesDomainEvent(UserCreatedEvent::class)]
class SendWelcomeEmailOnUserCreated implements EventHandlerInterface
{
    public function handleEvent(EventInterface $event): void
    {
        // Queue an email command
        app(CqrsServiceInterface::class)->dispatch(
            new SendWelcomeEmailCommand(
                userId: $event->userId,
                email: $event->email,
                name: '',
            ),
        );
    }
}

#[HandlesDomainEvent(UserCreatedEvent::class)]
class CreateDefaultProfileOnUserCreated implements EventHandlerInterface
{
    public function handleEvent(EventInterface $event): void
    {
        app(CqrsServiceInterface::class)->dispatch(
            new CreateDefaultProfileCommand(userId: $event->userId),
        );
    }
}
```

## Hydration from API request

```php
class UserController
{
    public function __construct(
        private readonly CqrsServiceInterface $cqrs,
    ) {}

    public function store(Request $request): JsonResponse
    {
        // Auto-hydrate command from request data
        $result = $this->cqrs->dispatch(
            CreateUserCommand::class,
            $request->validated(),
        );

        if ($result->isFailure()) {
            return response()->json(
                $result->getError()->toErrorArray(),
                $result->getError()->status(),
            );
        }

        return response()->json($result->getValue(), 201);
    }

    public function show(string $id): JsonResponse
    {
        $option = $this->cqrs->ask(
            GetUserQuery::class,
            ['userId' => $id],
        );

        return response()->json(
            $option->getOrFail(
                exceptionFactory: fn() => new NotFoundException('User not found'),
            ),
        );
    }
}
```

## Result/Option chaining

```php
// Result pattern
$result = $cqrs->dispatch(new CreateUserCommand(...));

$userId = $result->getOrFail(
    onFailure: function (Throwable $e) {
        logger()->error('User creation failed', ['error' => $e->getMessage()]);
        throw new InternalServerErrorException($e);
    },
    onSuccess: fn(mixed $value) => $value['id'],
);

// Option pattern
$option = $cqrs->ask(new GetUserQuery(userId: $id));

$userData = $option->getOrFail(
    exceptionFactory: fn() => new NotFoundException("User {$id} not found"),
    onSuccess: fn(array $data) => $data,
);

// Safe access
$email = $option->fetch('email'); // Returns single key or null
$data = $option->getOrElse(['name' => 'Anonymous']); // Fallback
```
