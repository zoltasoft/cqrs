<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Factories;

use ReflectionClass;
use Zolta\Cqrs\Attributes\HandlesQuery;

class QueryMapFactory
{
    /**
     * Build a query map from discovered classes.
     *
     * @param  array<int, class-string>  $classes  Fully-qualified class names to scan
     * @return array<class-string, array{handler?: class-string}>
     */
    public static function create(array $classes): array
    {
        $map = [];

        foreach ($classes as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $ref = new ReflectionClass($class);

            foreach ($ref->getAttributes(HandlesQuery::class) as $attr) {
                $inst = $attr->newInstance();
                $queryClass = $inst->queryClass;
                $map[$queryClass] ??= [];
                $map[$queryClass]['handler'] = $class;
            }
        }

        return $map;
    }
}
