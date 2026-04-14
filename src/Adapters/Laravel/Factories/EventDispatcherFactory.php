<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Factories;

use Illuminate\Contracts\Foundation\Application as LaravelApp;
use Psr\Log\LoggerInterface;
use Zolta\Cqrs\Events\Contracts\EventDispatcherInterface;
use Zolta\Cqrs\Laravel\Services\LaravelEventDispatcher;

final class EventDispatcherFactory
{
    /**
     * @param  array<string, array<int, class-string>>  $eventMap
     */
    public static function create(LaravelApp $laravelApp, array $eventMap, ?LoggerInterface $logger = null): EventDispatcherInterface
    {
        if (! $logger instanceof LoggerInterface && $laravelApp->bound(LoggerInterface::class)) {
            $logger = $laravelApp->make(LoggerInterface::class);
        }

        $dispatchers = [
            new LaravelEventDispatcher($laravelApp['events'], $logger),
        ];

        return \Zolta\Cqrs\Events\Factories\EventDispatcherFactory::create($dispatchers);
    }
}
