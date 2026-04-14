<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Database;

use Illuminate\Support\Facades\DB;
use Zolta\Cqrs\Contracts\TransactionManagerInterface;

final class LaravelTransactionManager implements TransactionManagerInterface
{
    public function begin(): void
    {
        DB::beginTransaction();
    }

    public function commit(): void
    {
        DB::commit();
    }

    public function rollback(): void
    {
        DB::rollBack();
    }

    /**
     * @template TReturn
     *
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    public function runInTransaction(callable $callback): mixed
    {
        return DB::transaction(\Closure::fromCallable($callback));
    }
}
