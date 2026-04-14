<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Providers;

use ReflectionClass;
use Zolta\Cqrs\Attributes\HandlesQuery;
use Zolta\Cqrs\Queries\Contracts\QueryBusInterface;

class QueryMapServiceProvider extends BaseMapServiceProvider
{
    protected function getConfigEntriesKey(): string
    {
        return 'queries';
    }

    protected function getMapType(): string
    {
        return 'query';
    }

    /**
     * @param  ReflectionClass<object>  $reflectionClass
     * @param  array<string, array<int|string, string>>  $map
     */
    protected function mapClass(ReflectionClass $reflectionClass, array &$map): void
    {
        foreach ($reflectionClass->getAttributes(HandlesQuery::class) as $attribute) {
            $inst = $attribute->newInstance();
            $queryClass = $inst->queryClass;
            $map[$queryClass] = ['handler' => $reflectionClass->getName()];
        }
    }

    public function boot(): void
    {
        if ($this->app->bound(QueryBusInterface::class)) {
            /** @var QueryBusInterface $bus */
            $bus = $this->app->make(QueryBusInterface::class);
            $queryMap = $this->app->make($this->getMapKey());

            foreach ($queryMap as $query => $meta) {
                if (! empty($meta['handler'])) {
                    $bus->register($query, $meta['handler']);
                }
            }
        }
    }
}
