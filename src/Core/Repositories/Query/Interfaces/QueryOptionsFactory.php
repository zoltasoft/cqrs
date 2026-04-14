<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Repositories\Query\Interfaces;

use Zolta\Domain\Repositories\Query\AbstractQueryOptions;

interface QueryOptionsFactory
{
    /**
     * Create a domain AbstractQueryOptions instance from raw inputs.
     *
     * Implementations live in infra and can sanitize / whitelist.
     *
     * @param  array<string, mixed>  $payload  Accepts keys: filters, include, sort, limit, page, context
     */
    public function make(array $payload = []): AbstractQueryOptions;
}
