<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Zolta\Cqrs\Events\Contracts\EventDispatcherInterface;
use Zolta\Cqrs\Laravel\Factories\EventDispatcherFactory;

class EventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EventDispatcherInterface::class, function (Application $application): EventDispatcherInterface {
            $eventMap = $application->make('event.map');

            return EventDispatcherFactory::create($application, $eventMap);
        });
    }

    public function boot(): void {}
}
