<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Providers;

use Illuminate\Support\ServiceProvider;
use Zolta\Cqrs\Laravel\Console\Commands\Cache\CacheMapsCommand;
use Zolta\Cqrs\Laravel\Console\Commands\Cache\ClearMapsCommand;

class ZoltaCommandServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CacheMapsCommand::class,
                ClearMapsCommand::class,
            ]);
        }
    }
}
