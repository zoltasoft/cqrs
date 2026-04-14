<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Repositories\Query;

use Zolta\Domain\Repositories\Query\AbstractQueryOptions;

/**
 * Lightweight infra concrete of AbstractQueryOptions.
 */
final class QueryOptions extends AbstractQueryOptions
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(array $payload = [])
    {
        parent::__construct($payload);
    }

    /**
     * @return array{
     *     filters: array<string, mixed>,
     *     include: list<string>,
     *     sort: list<string>,
     *     limit: int|null,
     *     page: int|null,
     *     context: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'filters' => $this->filters,
            'include' => $this->include,
            'sort' => $this->sort,
            'limit' => $this->limit,
            'page' => $this->page,
            'context' => $this->context,
        ];
    }
}
