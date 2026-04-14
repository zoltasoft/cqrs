<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Providers;

use Illuminate\Support\ServiceProvider;
use Zolta\Cqrs\Queries\Contracts\QueryBusInterface;
use Zolta\Cqrs\Queries\InMemoryQueryBus;
use Zolta\Framework\FrameworkRegistry;

class QueryBusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $binding = FrameworkRegistry::resolveBinding(QueryBusInterface::class) ?? InMemoryQueryBus::class;

        $this->app->scoped(QueryBusInterface::class, static fn ($app): QueryBusInterface =>
            // Let the container build it to honor dependencies/overrides.
            $app->make($binding));
    }
}
