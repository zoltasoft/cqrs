<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Contracts;

interface TransactionManagerInterface
{
    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;

    /**
     * Execute a callable within a transaction.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function runInTransaction(callable $callback): mixed;
}
