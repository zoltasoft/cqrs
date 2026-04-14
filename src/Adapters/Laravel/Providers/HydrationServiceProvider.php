<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Providers;

use Illuminate\Support\ServiceProvider;
use Zolta\Cqrs\Hydration\DefaultMessageHydrator;
use Zolta\Cqrs\Hydration\MessageHydratorInterface;
use Zolta\Framework\FrameworkRegistry;

final class HydrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $hydrator = FrameworkRegistry::resolveBinding(MessageHydratorInterface::class) ?? DefaultMessageHydrator::class;

        $this->app->singleton(
            MessageHydratorInterface::class,
            $hydrator
        );
    }
}
