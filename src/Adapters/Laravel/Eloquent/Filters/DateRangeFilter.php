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
class DateRangeFilter implements CoreFilter, FilterInterface
{
    public function __construct(
        private readonly ?string $from = null,
        private readonly ?string $to = null,
        /** @phpstan-ignore property.onlyWritten */
        private readonly string $format = 'Y-m-d'
    ) {}

    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, string $key): void
    {
        if ($this->from) {
            $builder->whereDate($key, '>=', $this->from);
        }

        if ($this->to) {
            $builder->whereDate($key, '<=', $this->to);
        }
    }
}
