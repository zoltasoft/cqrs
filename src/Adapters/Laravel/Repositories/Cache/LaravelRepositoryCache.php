<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Repositories\Cache;

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\TaggableStore as CacheTaggableStore;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Zolta\Cqrs\Repositories\Cache\CacheKeyGenerator;
use Zolta\Cqrs\Repositories\Cache\RepositoryCache;
use Zolta\Domain\ValueObjects\Pagination;

/**
 * @template TCacheValue
 */
final class LaravelRepositoryCache implements RepositoryCache
{
    private const ENVELOPE_VERSION = 1;

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

        $cached = $this->store()->get($key);

        if ($cached !== null) {
            /** @var TCacheValue */
            return $this->unwrap($cached);
        }

        /** @var TCacheValue $value */
        $value = $callback();

        $this->store()->put($key, $this->wrap($value), $ttl);

        return $value;
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

    // -------------------------------------------------------------------------
    // Serialization envelope
    // -------------------------------------------------------------------------

    /**
     * Wrap a value into a safe plain-array envelope before storing.
     * Eloquent models are encoded to arrays so PHP's unserialize() never
     * needs to resolve a class that may not be autoloaded yet.
     *
     * @return array{_v: int, _type: string, _data: mixed}
     */
    private function wrap(mixed $value): array
    {
        return [
            '_v' => self::ENVELOPE_VERSION,
            '_type' => $this->detectType($value),
            '_data' => $this->encode($value),
        ];
    }

    /**
     * Reconstruct the original value from a stored envelope.
     * Falls back gracefully for any value cached before this change was deployed.
     */
    private function unwrap(mixed $cached): mixed
    {
        if (! is_array($cached) || ! isset($cached['_v'], $cached['_type'], $cached['_data'])) {
            return $cached;
        }

        return $this->decode($cached['_type'], $cached['_data']);
    }

    private function detectType(mixed $value): string
    {
        if ($value instanceof Pagination) {
            return 'pagination';
        }
        if ($value instanceof EloquentCollection) {
            return 'eloquent_collection';
        }
        if ($value instanceof Collection) {
            return 'collection';
        }
        if ($value instanceof Model) {
            return 'model';
        }
        if (is_array($value)) {
            return 'array';
        }
        if (is_iterable($value)) {
            return 'iterable';
        }

        return 'scalar';
    }

    private function encode(mixed $value): mixed
    {
        if ($value instanceof Pagination) {
            return [
                'items' => array_map(
                    fn ($item): mixed => $item instanceof Model ? $this->encodeModel($item) : $item,
                    $value->items
                ),
                'total' => $value->total,
                'perPage' => $value->perPage,
                'currentPage' => $value->currentPage,
                'lastPage' => $value->lastPage,
            ];
        }

        if ($value instanceof Model) {
            return $this->encodeModel($value);
        }

        if ($value instanceof Collection) {
            return $value->map(
                fn ($item): mixed => $item instanceof Model ? $this->encodeModel($item) : $item
            )->all();
        }

        if (is_array($value)) {
            return array_map(
                fn ($item): mixed => $item instanceof Model ? $this->encodeModel($item) : $item,
                $value
            );
        }

        if (is_iterable($value)) {
            $result = [];
            foreach ($value as $k => $item) {
                $result[$k] = $item instanceof Model ? $this->encodeModel($item) : $item;
            }

            return $result;
        }

        return $value;
    }

    private function decode(string $type, mixed $data): mixed
    {
        return match ($type) {
            'model' => $this->decodeModel($data),

            'eloquent_collection' => EloquentCollection::make(
                array_map($this->decodeModel(...), $data)
            ),

            'collection' => Collection::make(
                array_map(
                    fn ($row): mixed => is_array($row) && isset($row['__class'])
                        ? $this->decodeModel($row)
                        : $row,
                    $data
                )
            ),

            'array', 'iterable' => array_map(
                fn ($row): mixed => is_array($row) && isset($row['__class'])
                    ? $this->decodeModel($row)
                    : $row,
                $data
            ),
            'pagination' => new Pagination(
                items: array_map(
                    fn ($row): mixed => is_array($row) && isset($row['__class'])
                        ? $this->decodeModel($row)
                        : $row,
                    $data['items']
                ),
                total: $data['total'],
                perPage: $data['perPage'],
                currentPage: $data['currentPage'],
                lastPage: $data['lastPage'],
            ),

            default => $data,
        };
    }

    // -------------------------------------------------------------------------
    // Model encode / decode
    // -------------------------------------------------------------------------

    /**
     * Reduce an Eloquent model to a plain array, encoding relations recursively.
     *
     * @return array{__class: class-string<Model>, attributes: array<string,mixed>, relations: array<string,mixed>, exists: bool}
     */
    private function encodeModel(Model $model): array
    {
        $relations = [];

        foreach ($model->getRelations() as $name => $relation) {
            if ($relation instanceof Model) {
                $relations[$name] = $this->encodeModel($relation);
            } elseif ($relation instanceof Collection) {
                $relations[$name] = $relation->map(
                    fn ($r): mixed => $r instanceof Model ? $this->encodeModel($r) : $r
                )->all();
            } else {
                // null or pivot — store as-is
                $relations[$name] = $relation;
            }
        }

        return [
            '__class' => $model::class,
            'attributes' => $model->getAttributes(),
            'relations' => $relations,
            'exists' => $model->exists,
        ];
    }

    /**
     * Reconstruct an Eloquent model from its encoded array.
     *
     * Uses `new $class` to trigger the autoloader correctly at retrieval time,
     * and `setRawAttributes(..., true)` so the model's "original" state matches
     * its attributes and it doesn't appear dirty.
     *
     * @param  array{__class: class-string<Model>, attributes: array<string,mixed>, relations: array<string,mixed>, exists: bool}  $data
     */
    private function decodeModel(array $data): Model
    {
        /** @var class-string<Model> $class */
        $class = $data['__class'];

        /** @var Model $model */
        $model = new $class;
        $model->setRawAttributes($data['attributes'], true);
        $model->exists = $data['exists'] ?? true;

        foreach ($data['relations'] as $name => $relData) {
            if ($relData === null) {
                $model->setRelation($name, null);

                continue;
            }

            if (is_array($relData) && isset($relData['__class'])) {
                $model->setRelation($name, $this->decodeModel($relData));

                continue;
            }

            if (is_array($relData)) {
                $items = array_map(
                    fn ($r): mixed => is_array($r) && isset($r['__class']) ? $this->decodeModel($r) : $r,
                    $relData
                );
                $model->setRelation($name, $model->newCollection($items));

                continue;
            }

            $model->setRelation($name, $relData);
        }

        return $model;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

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
