<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Repositories\Cache;

/**
 * No-op cache implementation used when a framework cache adapter is unavailable.
 */
final class NullRepositoryCache implements RepositoryCache
{
    public function remember(string $namespace, array $parameters, callable $callback, ?int $ttlSeconds = null): mixed
    {
        return $callback();
    }

    public function forget(string $namespace, array $parameters): void
    {
        // no-op
    }

    public function flushNamespace(string $namespace): void
    {
        // no-op
    }

    public function flushAll(): void
    {
        // no-op
    }
}
