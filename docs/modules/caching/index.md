---
title: Caching
description: Repository caching with hashed keys, namespace scoping, and automatic invalidation.
navigation:
  title: Caching
  order: 6
---

# Caching

Zolta CQRS includes a repository caching layer that transparently caches read operations with hashed cache keys and namespace-scoped invalidation.

## Architecture

```
Repository.all() / .first() / .show()
    │
    ├─ enableReadCaching = true?
    │   └─ RepositoryCache.remember(namespace, params, callback)
    │       └─ CacheKeyGenerator.generate(namespace, params)
    │           └─ HashedCacheKeyGenerator → 'zolta:namespace:md5hash'
    │               └─ Cache store (Redis, file, etc.)
    │
    └─ enableReadCaching = false?
        └─ Execute query directly
```

## RepositoryCache

The cache interface used by repositories:

```php
namespace Zolta\Cqrs\Repositories\Cache;

interface RepositoryCache
{
    public function remember(
        string $namespace,
        array $parameters,
        callable $callback,
        ?int $ttlSeconds = null,
    ): mixed;

    public function forget(string $namespace, array $parameters): void;
    public function flushNamespace(string $namespace): void;
    public function flushAll(): void;
}
```

| Method | Description |
|--------|-------------|
| `remember()` | Returns cached value or executes callback and caches result |
| `forget()` | Removes a specific cached entry |
| `flushNamespace()` | Removes all entries in a namespace |
| `flushAll()` | Clears the entire cache |

## CacheKeyGenerator

Generates deterministic cache keys from namespace and parameters:

```php
namespace Zolta\Cqrs\Repositories\Cache;

interface CacheKeyGenerator
{
    public function generate(string $namespace, array $parameters = []): string;
    public function prefix(): string;
}
```

## HashedCacheKeyGenerator

The default implementation using MD5 hashing:

```php
namespace Zolta\Cqrs\Repositories\Cache;

final class HashedCacheKeyGenerator implements CacheKeyGenerator
{
    public function __construct(private string $prefix = 'zolta');

    public function generate(string $namespace, array $parameters = []): string;
    public function prefix(): string;
}
```

**Key format:** `zolta:namespace:md5(json_encode($parameters))`

**Parameter normalization:**
- Booleans → `'0'` or `'1'`
- Objects → SPL object hash
- Arrays → recursively normalized
- Other values → cast to string

### Example keys

```php
$generator = new HashedCacheKeyGenerator('app');

// Simple
$key = $generator->generate('users', ['status' => 'active']);
// → 'app:users:a1b2c3d4e5f6...'

// With nested params
$key = $generator->generate('users.roles', [
    'filters' => ['status' => 'active'],
    'page' => 1,
    'limit' => 20,
]);
// → 'app:users.roles:f6e5d4c3b2a1...'
```

## NullRepositoryCache

A no-op cache implementation for disabling caching:

```php
namespace Zolta\Cqrs\Repositories\Cache;

class NullRepositoryCache implements RepositoryCache
{
    public function remember(string $namespace, array $parameters, callable $callback, ?int $ttlSeconds = null): mixed
    {
        return $callback(); // Always executes the callback
    }

    public function forget(string $namespace, array $parameters): void {}
    public function flushNamespace(string $namespace): void {}
    public function flushAll(): void {}
}
```

## Enabling caching in repositories

### Configuration

```php
class UserRepository extends EloquentBaseRepository
{
    // Enable caching
    protected bool $enableReadCaching = true;

    // Cache TTL (minutes or seconds)
    protected int $cacheTtlMinutes = 10;
    protected int $cacheTtlSeconds = 0; // Takes precedence if > 0

    // Use tagged cache for Laravel's tagged cache stores
    protected bool $useTaggedCache = false;

    protected function modelClass(): string
    {
        return User::class;
    }
}
```

### How it works

When `enableReadCaching` is `true`, read operations automatically use the cache:

```php
// First call: executes query, caches result
$users = $repository->all($options);

// Second call (same params): returns cached result
$users = $repository->all($options);

// Different params: separate cache entry
$users = $repository->all(new QueryOptions(['filters' => ['role' => 'admin']]));
```

### Cache namespaces

Each repository method uses a scoped namespace:

```php
// Namespace format: {ModelClass}.{method}
// Examples:
// - 'App\Models\User.all'
// - 'App\Models\User.first'
// - 'App\Models\User.show'
// - 'App\Models\User.count'
```

### Manual cache invalidation

```php
// Forget a specific entry
$repository->cache()->forget('App\Models\User.all', ['status' => 'active']);

// Flush all entries for a method
$repository->cache()->flushNamespace('App\Models\User.all');

// Flush everything
$repository->cache()->flushAll();
```

## Custom cache implementation

Implement `RepositoryCache` for a custom cache store:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use Zolta\Cqrs\Repositories\Cache\RepositoryCache;
use Zolta\Cqrs\Repositories\Cache\HashedCacheKeyGenerator;

class RedisRepositoryCache implements RepositoryCache
{
    private HashedCacheKeyGenerator $keyGenerator;

    public function __construct(
        private readonly \Redis $redis,
        private readonly int $defaultTtl = 300,
    ) {
        $this->keyGenerator = new HashedCacheKeyGenerator('app');
    }

    public function remember(
        string $namespace,
        array $parameters,
        callable $callback,
        ?int $ttlSeconds = null,
    ): mixed {
        $key = $this->keyGenerator->generate($namespace, $parameters);
        $cached = $this->redis->get($key);

        if ($cached !== false) {
            return unserialize($cached);
        }

        $value = $callback();
        $this->redis->setex($key, $ttlSeconds ?? $this->defaultTtl, serialize($value));

        return $value;
    }

    public function forget(string $namespace, array $parameters): void
    {
        $key = $this->keyGenerator->generate($namespace, $parameters);
        $this->redis->del($key);
    }

    public function flushNamespace(string $namespace): void
    {
        $prefix = $this->keyGenerator->prefix() . ':' . $namespace . ':*';
        $keys = $this->redis->keys($prefix);
        if (!empty($keys)) {
            $this->redis->del($keys);
        }
    }

    public function flushAll(): void
    {
        $prefix = $this->keyGenerator->prefix() . ':*';
        $keys = $this->redis->keys($prefix);
        if (!empty($keys)) {
            $this->redis->del($keys);
        }
    }
}
```

Override `cache()` in your repository:

```php
protected function cache(): RepositoryCache
{
    return new RedisRepositoryCache($this->redis);
}
```
