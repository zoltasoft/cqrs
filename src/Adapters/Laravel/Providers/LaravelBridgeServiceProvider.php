<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Providers;

use Illuminate\Support\ServiceProvider;
use Zolta\Support\ContainerRegistry;
use Zolta\Support\ZoltaForgeContainer;

final class LaravelBridgeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        ContainerRegistry::set(new ZoltaForgeContainer($this->app));
    }
}
