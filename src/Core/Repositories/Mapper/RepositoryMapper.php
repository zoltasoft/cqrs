<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Repositories\Mapper;

interface RepositoryMapper
{
    /**
     * Map a persistence model to a domain entity.
     * @param object $model
     * @return object
     */
    public static function toDomain(object $model): object;

    /**
     * Map a domain entity to a persistence model or array.
     * @param object $entity
     * @return object|array
     */
    public static function toPersistence(object $entity): object|array;
}
