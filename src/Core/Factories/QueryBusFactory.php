<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Factories;

use Psr\Container\ContainerInterface;
use Zolta\Cqrs\Queries\Contracts\QueryBusInterface;
use Zolta\Cqrs\Queries\InMemoryQueryBus;

class QueryBusFactory
{
    /**
     * Build the default query bus stack.
     */
    public static function create(ContainerInterface $container): QueryBusInterface
    {
        return new InMemoryQueryBus($container);
    }
}
