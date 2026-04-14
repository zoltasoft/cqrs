<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Queries\Contracts;

interface QueryBusInterface
{
    /**
     * Register a query handler mapping.
     *
     * @param  class-string<QueryInterface>  $query
     * @param  class-string  $handler
     */
    public function register(string $query, string $handler): void;

    /**
     * Ask for a query result.
     *
     * @param  mixed  ...$args
     */
    public function ask(QueryInterface $query, ...$args): mixed;
}
