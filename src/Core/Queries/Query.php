<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Queries;

use ReflectionClass;
use Zolta\Cqrs\Queries\Contracts\QueryInterface;
use Zolta\Support\Traits\Normalizable;

abstract class Query implements QueryInterface
{
    use Normalizable;

    public static function alias(): string
    {
        $reflectionClass = new ReflectionClass(static::class);
        $shortName = $reflectionClass->getShortName();

        // Remove "Query" suffix if present
        $base = preg_replace('/Query$/', '', $shortName);

        // Convert PascalCase to camelCase
        return lcfirst((string) $base);
    }
}
