<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Events\Attributes;

use Attribute;

/**
 * Apply this attribute to an *infrastructure* (Laravel) event class to declare
 * the domain event class it wraps.
 *
 * Example:
 *   #[HandlesDomainEvent(\App\MyService\Domain\Events\UserRegistered::class)]
 *   class UserRegisteredEvent { public function __construct(UserRegistered $domain) { ... } }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class HandlesDomainEvent
{
    /**
     * @param  class-string  $domainEventClass  Fully-qualified domain event class this infra event wraps
     */
    public function __construct(
        public string $domainEventClass
    ) {}
}
