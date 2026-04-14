<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Eloquent\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Zolta\Cqrs\Laravel\Eloquent\Contracts\FilterInterface;

/**
 * @implements FilterInterface<Model>
 */
class RelationExistsFilter implements FilterInterface
{
    public function __construct(
        private readonly bool $shouldExist = true
    ) {}

    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, string $key): void
    {
        if ($this->shouldExist) {
            $builder->whereHas($key);
        } else {
            $builder->whereDoesntHave($key);
        }
    }
}
