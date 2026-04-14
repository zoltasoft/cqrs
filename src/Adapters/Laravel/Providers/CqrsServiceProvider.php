<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Providers;

use Illuminate\Support\ServiceProvider;
use Zolta\Cqrs\Contracts\CqrsServiceInterface;
use Zolta\Cqrs\Services\Cqrs;
use Zolta\Framework\FrameworkRegistry;

class CqrsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $implementation = FrameworkRegistry::resolveBinding(CqrsServiceInterface::class) ?? Cqrs::class;

        $this->app->bind(CqrsServiceInterface::class, $implementation);
    }
}
