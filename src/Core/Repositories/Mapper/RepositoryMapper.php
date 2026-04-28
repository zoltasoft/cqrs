<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Repositories\Mapper;

interface RepositoryMapper
{
    /**
     * Map a persistence model to a domain entity.
     */
    public static function toDomain(object $model): object;

    /**
     * Map a domain entity to a persistence model or array.
     *
     * @return object|array<string, mixed>
     */
    public static function toPersistence(object $entity): object|array;
}
