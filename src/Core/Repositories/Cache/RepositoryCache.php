<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Repositories\Cache;

interface RepositoryCache
{
    /**
     * Remember the value for a key resolved from namespace + parameters.
     *
     * @param  array<string,mixed>  $parameters
     * @param  callable():mixed  $callback
     */
    public function remember(string $namespace, array $parameters, callable $callback, ?int $ttlSeconds = null): mixed;

    /**
     * Forget a single cached entry.
     *
     * @param  array<string,mixed>  $parameters
     */
    public function forget(string $namespace, array $parameters): void;

    /**
     * Flush every entry that belongs to the namespace.
     */
    public function flushNamespace(string $namespace): void;

    /**
     * Flush every entry tracked for the repository (all namespaces).
     */
    public function flushAll(): void;
}
