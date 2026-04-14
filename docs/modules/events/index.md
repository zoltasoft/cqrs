---
title: Events
description: Domain event dispatching, listener registration, and framework integration.
navigation:
  title: Events
  order: 3
---

# Events

Zolta CQRS provides a domain event system that integrates with framework-level event dispatchers. Domain events are recorded by aggregates during command execution and automatically dispatched by the `EventDispatchingCommandBus`.

## Event lifecycle

```
1. Aggregate records event      → user->recordThat(new UserCreatedEvent(...))
2. Handler returns Result       → Result::success($data, $user->releaseEvents())
3. EventDispatchingCommandBus   → Extracts events from Result
4. EventDispatcher              → Routes to registered listeners
5. Listeners handle event       → Send email, update projections, etc.
```

## EventDispatcherInterface

```php
namespace Zolta\Cqrs\Events\Contracts;

interface EventDispatcherInterface
{
    public function dispatch(EventInterface $event): void;
    public function registerListeners(array $listeners): void;
    public function listen(string|array $events, callable|string $listener): void;
}
```

## EventHandlerInterface

```php
namespace Zolta\Cqrs\Events\Contracts;

interface EventHandlerInterface
{
    public function handleEvent(EventInterface $event): void;
}
```

## EventDispatcher

The core dispatcher multiplexes events to multiple sub-dispatchers:

```php
namespace Zolta\Cqrs\Events;

class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(array $dispatchers);

    public function dispatch(EventInterface $event): void;
    public function listen(string|array $events, callable|string $listener): void;
    public function registerListeners(array $listeners): void;
}
```

This dispatcher forwards events to all registered sub-dispatchers, allowing integration with both Zolta's event system and the host framework's native events.

## Defining domain events

Domain events implement `EventInterface` from zolta-forge:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Events;

use Zolta\Domain\Events\Contracts\EventInterface;

final readonly class UserCreatedEvent implements EventInterface
{
    public function __construct(
        public string $userId,
        public string $email,
        public \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {}

    public function eventName(): string
    {
        return 'user.created';
    }
}
```

## Recording events in aggregates

```php
use Zolta\Domain\Aggregates\AggregateRoot;

class User extends AggregateRoot
{
    public static function create(/* ... */): self
    {
        $user = new self(/* ... */);

        $user->recordThat(new UserCreatedEvent(
            userId: (string) $user->id,
            email: (string) $user->email,
        ));

        return $user;
    }

    public function changeEmail(Email $newEmail): void
    {
        $oldEmail = $this->email;
        $this->email = $newEmail;

        $this->recordThat(new UserEmailChangedEvent(
            userId: (string) $this->id,
            oldEmail: (string) $oldEmail,
            newEmail: (string) $newEmail,
        ));
    }
}
```

## Releasing events in handlers

Events are released via `Result` and dispatched by the bus:

```php
#[HandlesCommand(CreateUserCommand::class)]
class CreateUserHandler
{
    public function __invoke(CreateUserCommand $command): Result
    {
        $user = User::create(/* ... */);
        $this->repository->save($user);

        // Events are extracted by EventDispatchingCommandBus
        return Result::success(
            value: $user->toArray(),
            events: $user->releaseEvents(),
        );
    }
}
```

## Registering event listeners

### Via `#[HandlesDomainEvent]` attribute

```php
use Zolta\Cqrs\Events\Attributes\HandlesDomainEvent;

#[HandlesDomainEvent(UserCreatedEvent::class)]
class SendWelcomeEmailListener implements EventHandlerInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
    ) {}

    public function handleEvent(EventInterface $event): void
    {
        $this->mailer->send(
            to: $event->email,
            template: 'welcome',
            data: ['userId' => $event->userId],
        );
    }
}
```

### `#[HandlesDomainEvent]` attribute

```php
#[Attribute(Attribute::TARGET_CLASS)]
class HandlesDomainEvent
{
    public function __construct(
        public string $domainEventClass,
    );
}
```

The `EventMapServiceProvider` scans configured paths for this attribute and registers the mappings automatically.

### Manual registration

```php
$dispatcher->listen(
    UserCreatedEvent::class,
    SendWelcomeEmailListener::class,
);

// Multiple events
$dispatcher->listen(
    [UserCreatedEvent::class, UserUpdatedEvent::class],
    AuditLogListener::class,
);

// Batch registration
$dispatcher->registerListeners([
    UserCreatedEvent::class => SendWelcomeEmailListener::class,
    UserDeletedEvent::class => CleanupListener::class,
]);
```

## Laravel integration

The `LaravelEventDispatcher` bridges Zolta events with Laravel's event system:

```php
namespace Zolta\Cqrs\Adapters\Laravel\Services;

class LaravelEventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        LaravelDispatcher $laravelDispatcher,
        ?LoggerInterface $logger = null,
    );

    public function dispatch(EventInterface $event): void;
    public function listen(string|array $events, callable|string $listener): void;
    public function registerListeners(array $listeners): void;
}
```

This means domain events can be handled by both:
- Zolta `#[HandlesDomainEvent]` listeners
- Standard Laravel event listeners and subscribers

## Dispatching events manually

Via `ApplicationService`:

```php
$this->applicationService->dispatchEvents([
    new UserCreatedEvent($userId, $email),
    new WelcomeBonusGrantedEvent($userId, 100),
]);
```

Via `EventDispatcherInterface` directly:

```php
$dispatcher->dispatch(new UserCreatedEvent($userId, $email));
```

## Event patterns

### Event with payload

```php
final readonly class OrderPlacedEvent implements EventInterface
{
    public function __construct(
        public string $orderId,
        public string $userId,
        public float $total,
        public array $items,
        public \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {}

    public function eventName(): string
    {
        return 'order.placed';
    }
}
```

### Multiple listeners per event

```php
// Configuration scanned automatically:

#[HandlesDomainEvent(OrderPlacedEvent::class)]
class SendOrderConfirmationEmail implements EventHandlerInterface { /* ... */ }

#[HandlesDomainEvent(OrderPlacedEvent::class)]
class UpdateInventoryProjection implements EventHandlerInterface { /* ... */ }

#[HandlesDomainEvent(OrderPlacedEvent::class)]
class NotifyWarehouseListener implements EventHandlerInterface { /* ... */ }
```

### Event listener as inline closure

```php
$dispatcher->listen(UserCreatedEvent::class, function (EventInterface $event) {
    logger()->info('User created', ['userId' => $event->userId]);
});
```
