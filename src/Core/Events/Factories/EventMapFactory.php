<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Events\Factories;

use ReflectionClass;
use Zolta\Cqrs\Events\Attributes\HandlesDomainEvent;

class EventMapFactory
{
    /**
     * Build an event map from PHP files.
     *
     * @param  array<int, string>  $files  PHP files to scan
     * @param  callable  $classLoader  Function to resolve FQCN from file
     * @param  callable|null  $singletonRegistrar  Optional callback to register singletons
     * @return array<class-string, class-string>
     */
    public static function create(array $files, callable $classLoader, ?callable $singletonRegistrar = null): array
    {
        $map = [];

        foreach ($files as $file) {
            $fqcn = $classLoader($file);
            if (! is_string($fqcn) || $fqcn === '' || ! class_exists($fqcn, true)) {
                continue;
            }

            $ref = new ReflectionClass($fqcn);

            foreach ($ref->getAttributes(HandlesDomainEvent::class) as $attr) {
                $inst = $attr->newInstance();
                $domainEvent = $inst->domainEventClass;
                $map[$domainEvent] = $fqcn;

                if ($singletonRegistrar) {
                    $singletonRegistrar($fqcn);
                }
            }
        }

        return $map;
    }
}
