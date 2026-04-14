<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Contracts;

use Zolta\Cqrs\Commands\Contracts\CommandInterface;
use Zolta\Cqrs\Queries\Contracts\QueryInterface;
use Zolta\Domain\Interfaces\VO;

interface CqrsServiceInterface
{
    /**
     * Dispatch a command or command class with optional data and context.
     *
     * @param  CommandInterface|string  $commandOrClass  Command instance or class-string
     * @param  mixed  ...$args  Additional arguments. If class-string: first arg is data array, second is context array
     */
    public function dispatch(CommandInterface|string $commandOrClass, mixed ...$args): mixed;

    /**
     * Execute a query or query class with optional data and context.
     *
     * @param  QueryInterface|string  $queryOrClass  Query instance or class-string
     * @param  mixed  ...$args  Additional arguments. If class-string: first arg is data array, second is context array
     */
    public function ask(QueryInterface|string $queryOrClass, mixed ...$args): mixed;

    /**
     * Resolve and execute a command or query (class-string accepted).
     *
     * @param  mixed  ...$args  If $message is class-string: first arg optionally is data array, rest forwarded to bus
     */
    public function run(CommandInterface|QueryInterface|string $message, mixed ...$args): mixed;

    /**
     * Create or resolve any Value Object, Command, or Query.
     *
     * Note: signature changed — no context param. Pass all options via
     * the schema payloads or buildVo short-hand that embed runtime preprocessors/options.
     *
     * @param  class-string|object  $voOrClass
     * @param  array<string,mixed>  $data
     * @return VO|CommandInterface|QueryInterface|object
     */
    public function make(string|object $voOrClass, array $data = []): mixed;
}
