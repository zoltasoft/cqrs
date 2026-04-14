<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Providers;

use Illuminate\Support\ServiceProvider;
use Zolta\Cqrs\Repositories\Query\QueryOptionsFactory;
use Zolta\Cqrs\Repositories\Query\QueryOptionsFactory as QueryQueryOptionsFactory;

class QueryOptionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(QueryOptionsFactory::class, QueryQueryOptionsFactory::class);
    }
}
