<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Eloquent\Contracts;

use Illuminate\Database\Eloquent\Builder;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 */
interface FilterInterface
{
    /**
     * Apply the filter to the query builder.
     *
     * @param  Builder<TModel>  $builder
     */
    public function apply(Builder $builder, string $key): void;
}
