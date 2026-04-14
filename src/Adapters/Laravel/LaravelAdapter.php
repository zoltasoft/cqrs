<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel;

use Illuminate\Foundation\Application;
use Zolta\Cqrs\Contracts\CqrsServiceInterface;
use Zolta\Cqrs\Hydration\DefaultMessageHydrator;
use Zolta\Cqrs\Hydration\MessageHydratorInterface;
use Zolta\Cqrs\Laravel\Eloquent\EloquentBaseRepository;
use Zolta\Cqrs\Queries\Contracts\QueryBusInterface;
use Zolta\Cqrs\Queries\InMemoryQueryBus;
use Zolta\Cqrs\Repositories\BaseRepository;
use Zolta\Cqrs\Services\Cqrs;
use Zolta\Framework\FrameworkAdapterInterface;

final class LaravelAdapter implements FrameworkAdapterInterface
{
    public static function supports(): bool
    {
        return class_exists(Application::class)
            && function_exists('app');
    }

    public static function priority(): int
    {
        return 90;
    }

    /**
     * @return array<class-string, class-string>
     */
    public static function bindings(): array
    {
        return [
            CqrsServiceInterface::class => Cqrs::class,
            QueryBusInterface::class => InMemoryQueryBus::class,
            MessageHydratorInterface::class => DefaultMessageHydrator::class,
            BaseRepository::class => EloquentBaseRepository::class,
        ];
    }
}
