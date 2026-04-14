<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Eloquent\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Zolta\Cqrs\Laravel\Eloquent\Contracts\FilterInterface;
use Zolta\Cqrs\Repositories\Filters\FilterInterface as CoreFilter;

/**
 * @implements FilterInterface<Model>
 */
class SearchFilter implements CoreFilter, FilterInterface
{
    /**
     * @param  list<string>  $searchableFields
     */
    public function __construct(
        private readonly string $searchTerm,
        private readonly array $searchableFields = []
    ) {}

    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, string $key): void
    {
        $builder->where(function ($q): void {
            foreach ($this->searchableFields as $searchableField) {
                $q->orWhere($searchableField, 'like', "%{$this->searchTerm}%");
            }
        });
    }
}
