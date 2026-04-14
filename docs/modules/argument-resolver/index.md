---
title: Argument Resolver
description: Automatic handler method selection and dependency injection.
navigation:
  title: Argument Resolver
  order: 9
---

# Argument Resolver

The `ArgumentResolver` automatically determines which method to call on a handler and resolves all required arguments from the container, command object, and provided context.

## How it works

```
Handler class
    │
    ├─ 1. selectMethod() → Which method to call?
    │   ├─ __invoke() if callable
    │   ├─ Attribute-specified method
    │   └─ Default: handle() / validate()
    │
    └─ 2. resolveMethodArguments() → What to pass?
        ├─ Command/Query object (by type match)
        ├─ Provided arguments (by type or position)
        ├─ Container-resolved services (by type-hint)
        ├─ Default values
        └─ Variadic collection
```

## ArgumentResolver

```php
namespace Zolta\Cqrs\Utils;

class ArgumentResolver
{
    public function __construct(private readonly ContainerInterface $container);

    public function selectMethod(
        object|string $handler,
        string $targetClass,
        string $role = 'handler',
    ): string;

    public function resolveMethodArguments(
        object|string $handler,
        string $methodName,
        object $command,
        array $providedArgs,
    ): array;
}
```

## Method selection

`selectMethod()` determines which method to call on the handler:

### Resolution priority

1. **`__invoke()`** — If the handler is callable
2. **Attribute-specified method** — Via `#[HandlesCommand]`, `#[ValidatesCommand]`, or `#[HandlesQuery]` with a `methodName` parameter
3. **Default fallback:**
   - Role `'handler'` → `handle()`
   - Role `'validator'` → `validate()`
   - Role `'query'` → `handle()`

### Examples

```php
// 1. Callable handler → __invoke()
#[HandlesCommand(CreateUserCommand::class)]
class CreateUserHandler
{
    public function __invoke(CreateUserCommand $command): Result { /* ... */ }
}

// 2. Custom method name via attribute
#[HandlesCommand(CreateUserCommand::class, methodName: 'execute')]
class CreateUserHandler
{
    public function execute(CreateUserCommand $command): Result { /* ... */ }
}

// 3. Default fallback → handle()
#[HandlesCommand(CreateUserCommand::class)]
class CreateUserHandler
{
    public function handle(CreateUserCommand $command): Result { /* ... */ }
}
```

### Caching

Method selections are cached by `{handlerClass}|{targetClass}|{role}` to avoid repeated Reflection lookups.

## Argument resolution

`resolveMethodArguments()` builds the argument list for the selected method:

### Resolution algorithm (per parameter)

```
For each parameter in method signature:
    │
    ├─ Type matches the command/query? → Inject command
    │
    ├─ Provided arg is instance of parameter type? → Use it (consumed)
    │
    ├─ Provided arg is string matching a class name?
    │   └─ Try container resolution
    │
    ├─ Container has the type-hinted class? → Resolve from container
    │
    ├─ Parameter is untyped? → Consume next positional arg
    │
    ├─ Parameter has default value? → Use default
    │
    └─ Cannot resolve? → Throw RuntimeException
```

### Variadic parameters

If the last parameter is variadic, remaining arguments matching its type are collected:

```php
public function handle(
    CreateUserCommand $command,
    LoggerInterface $logger,
    string ...$tags, // Collects remaining string args
): Result { /* ... */ }
```

### Caching

Parameter metadata is cached by `{handlerClass}::{methodName}`.

## Practical examples

### Constructor-injected handler

```php
#[HandlesCommand(CreateUserCommand::class)]
class CreateUserHandler
{
    public function __construct(
        private readonly UserRepositoryInterface $repository,
    ) {}

    public function __invoke(CreateUserCommand $command): Result
    {
        // repository: from constructor DI
        // command: injected by ArgumentResolver
    }
}
```

### Method-injected dependencies

```php
#[HandlesCommand(CreateUserCommand::class)]
class CreateUserHandler
{
    public function __invoke(
        CreateUserCommand $command,         // Matched by type to command
        UserRepositoryInterface $repo,     // Resolved from container
        LoggerInterface $logger,           // Resolved from container
    ): Result {
        $logger->info('Creating user', ['email' => $command->email]);
        // ...
    }
}
```

### Mixed injection with provided args

```php
// Dispatch with extra arguments
$cqrs->dispatch($command, $tenantContext, $auditLogger);

// Handler receives them
public function __invoke(
    CreateUserCommand $command,       // The command object
    TenantContext $tenant,            // Matched from provided args
    AuditLogger $audit,              // Matched from provided args
    UserRepositoryInterface $repo,   // From container
): Result { /* ... */ }
```

### Validator with shared dependencies

```php
#[ValidatesCommand(CreateUserCommand::class)]
class CreateUserValidator
{
    public function validate(
        CreateUserCommand $command,
        UserRepositoryInterface $repository, // From container
    ): void {
        if ($repository->findByEmail($command->email)) {
            throw new ValidationException([
                'email' => 'Email already registered.',
            ]);
        }
    }
}
```
