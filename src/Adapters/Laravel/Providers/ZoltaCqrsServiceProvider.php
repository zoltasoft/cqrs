<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Providers;

use Illuminate\Support\ServiceProvider;
use Zolta\Cqrs\Contracts\TransactionManagerInterface;
use Zolta\Cqrs\Laravel\Database\LaravelTransactionManager;

class ZoltaCqrsServiceProvider extends ServiceProvider
{
    /**
     * Register all CQRS service providers in order.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/zolta.php', 'zolta');

        // Transaction support
        $this->app->bind(TransactionManagerInterface::class, LaravelTransactionManager::class);

        // Core bindings
        $this->app->register(LaravelBridgeServiceProvider::class);
        $this->app->register(HydrationServiceProvider::class);

        // CQRS buses
        $this->app->register(CqrsServiceProvider::class);
        $this->app->register(QueryBusServiceProvider::class);
        $this->app->register(CommandBusServiceProvider::class);
        $this->app->register(EventServiceProvider::class);

        // Map scanning (commands, queries, events)
        $this->app->register(CommandMapServiceProvider::class);
        $this->app->register(QueryMapServiceProvider::class);
        $this->app->register(EventMapServiceProvider::class);

        // Query options & artisan commands
        $this->app->register(QueryOptionsServiceProvider::class);
        $this->app->register(ZoltaCommandServiceProvider::class);
    }

    /**
     * Boot: publish configuration.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/zolta.php' => config_path('zolta.php'),
        ], 'zolta-config');
    }
}
