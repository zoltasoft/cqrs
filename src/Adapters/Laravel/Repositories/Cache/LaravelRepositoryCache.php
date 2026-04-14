<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Repositories\Cache;

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\TaggableStore as CacheTaggableStore;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Zolta\Cqrs\Repositories\Cache\CacheKeyGenerator;
use Zolta\Cqrs\Repositories\Cache\RepositoryCache;

/**
 * @template TCacheValue
 */
final class LaravelRepositoryCache implements RepositoryCache
{
    private ?CacheRepository $cacheRepository = null;

    public function __construct(
        private readonly CacheKeyGenerator $cacheKeyGenerator,
        private readonly string $tag,
        private readonly int $defaultTtlSeconds = 300,
        private readonly bool $useTaggedCache = true,
    ) {}

    /**
     * @param  callable():TCacheValue  $callback
     * @return TCacheValue
     */
    public function remember(string $namespace, array $parameters, callable $callback, ?int $ttlSeconds = null): mixed
    {
        $key = $this->key($namespace, $parameters);
        $ttl = $ttlSeconds ?? $this->defaultTtlSeconds;

        /** @var \Closure():TCacheValue $closure */
        $closure = $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback);

        /** @var TCacheValue $result */
        $result = $this->store()->remember($key, $ttl, $closure);

        return $result;
    }

    public function forget(string $namespace, array $parameters): void
    {
        $key = $this->key($namespace, $parameters);
        $this->store()->forget($key);
    }

    public function flushNamespace(string $namespace): void
    {
        if ($this->canUseTags()) {
            Cache::tags([$this->tag])->flush();

            return;
        }

        if ($this->flushPattern($this->namespaceKey($namespace))) {
            return;
        }

        $this->store()->getStore()->flush();
    }

    public function flushAll(): void
    {
        if ($this->canUseTags()) {
            Cache::tags([$this->tag])->flush();

            return;
        }

        if ($this->flushPattern(sprintf('%s.', $this->tag))) {
            return;
        }

        $this->store()->getStore()->flush();
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function key(string $namespace, array $parameters): string
    {
        $namespaceKey = $this->namespaceKey($namespace);

        return $this->cacheKeyGenerator->generate($namespaceKey, $parameters);
    }

    private function namespaceKey(string $namespace): string
    {
        return sprintf('%s.%s', $this->tag, $namespace);
    }

    private function store(): CacheRepository
    {
        if ($this->canUseTags()) {
            return $this->cacheRepository ??= Cache::tags([$this->tag]);
        }

        return Cache::store();
    }

    private function canUseTags(): bool
    {
        return $this->useTaggedCache && Cache::getStore() instanceof CacheTaggableStore;
    }

    private function flushPattern(string $namespaceKey): bool
    {
        if (config('cache.default') !== 'redis') {
            return false;
        }

        $store = Cache::getStore();
        if (! $store instanceof RedisStore) {
            return false;
        }

        $connection = $store->connection();
        $prefix = $store->getPrefix();

        $pattern = sprintf('%s%s:%s*', $prefix, $this->keyGeneratorPrefix(), $namespaceKey);

        $iterator = null;
        while (is_array($keys = $connection->scan($iterator, $pattern)) && $keys !== []) {
            foreach ($keys as $key) {
                $cacheKey = (string) str_replace($prefix, '', $key);
                Cache::forget($cacheKey);
            }
        }

        return true;
    }

    private function keyGeneratorPrefix(): string
    {
        return $this->cacheKeyGenerator->prefix();
    }
}
