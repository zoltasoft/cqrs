<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Providers;

use ReflectionClass;
use Zolta\Cqrs\Events\Attributes\HandlesDomainEvent;

class EventMapServiceProvider extends BaseMapServiceProvider
{
    protected function getConfigEntriesKey(): string
    {
        return 'infrastructure_events';
    }

    protected function getMapType(): string
    {
        return 'event';
    }

    /**
     * For each infrastructure event class that has #[HandlesDomainEvent(DomainEvent::class)]
     * add an entry: $map[DomainEvent::class][] = InfrastructureEventClass::class
     */
    /**
     * @param  ReflectionClass<object>  $reflectionClass
     * @param  array<string, array<int|string, string>>  $map
     */
    protected function mapClass(ReflectionClass $reflectionClass, array &$map): void
    {
        foreach ($reflectionClass->getAttributes(HandlesDomainEvent::class) as $attribute) {
            $instance = $attribute->newInstance();
            $domainEventClass = $instance->domainEventClass;

            // --- Defensive checks
            if (
                ! is_string($domainEventClass) ||
                $domainEventClass === '' ||
                ! class_exists($domainEventClass)
            ) {
                continue;
            }

            // Prevent self-references (infra == domain)
            if ($reflectionClass->getName() === $domainEventClass) {
                continue;
            }

            // Append infra wrapper for the domain event key
            $map[$domainEventClass][] = $reflectionClass->getName();
        }
    }
}
