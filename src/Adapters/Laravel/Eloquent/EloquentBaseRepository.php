<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;
use Zolta\Cqrs\Laravel\Eloquent\Contracts\FilterInterface;
use Zolta\Cqrs\Laravel\Eloquent\Traits\EloquentCrud;
use Zolta\Cqrs\Laravel\Repositories\Cache\LaravelRepositoryCache;
use Zolta\Cqrs\Repositories\Cache\CacheKeyGenerator;
use Zolta\Cqrs\Repositories\Cache\HashedCacheKeyGenerator;
use Zolta\Cqrs\Repositories\Cache\RepositoryCache;
use Zolta\Cqrs\Repositories\Query\Interfaces\QueryDefinition;
use Zolta\Cqrs\Repositories\Query\RepositoryQuery;
use Zolta\Cqrs\Repositories\Query\Services\AbstractRepository;
use Zolta\Domain\ValueObjects\Pagination;

/**
 * Base repository for Eloquent-backed infrastructure implementations.
 *
 * Responsibilities:
 *  - translate domain query objects into Eloquent builders
 *  - apply filtering, relation includes and field selection consistently
 *  - provide a framework-agnostic caching facade for read operations
 *
 * @template TModel of Model
 */
abstract class EloquentBaseRepository extends AbstractRepository
{
    use EloquentCrud;

    private RepositoryCache $repositoryCache;

    private ?CacheKeyGenerator $cacheKeyGenerator = null;

    protected function cache(): RepositoryCache
    {
        if (! isset($this->repositoryCache)) {
            $this->repositoryCache = new LaravelRepositoryCache(
                cacheKeyGenerator: $this->cacheKeyGenerator(),
                tag: $this->cacheTag,
                defaultTtlSeconds: $this->cacheTtlSeconds(),
                useTaggedCache: $this->useTaggedCache,
            );
        }

        return $this->repositoryCache;
    }

    /**
     * Post-write hook: bust cache after writes.
     */
    protected function afterWrite(): void
    {
        $this->bustCache();
    }

    // Example: ensure to call afterWrite() in create/update/delete methods in EloquentCrud trait or override if needed.

    protected function cacheKeyGenerator(): CacheKeyGenerator
    {
        return $this->cacheKeyGenerator ??= new HashedCacheKeyGenerator('zolta');
    }

    protected function cacheTag(): string
    {
        return $this->modelClass();
    }

    protected function cacheNamespace(string $segment): string
    {
        return sprintf('%s.%s', $this->modelClass(), $segment);
    }

    /**
     * Resolve an Eloquent query builder from the repository query.
     *
     * @return Builder<TModel>
     */
    protected function buildQuery(RepositoryQuery $repositoryQuery): Builder
    {
        $model = $this->modelClass();
        /** @var class-string<TModel> $model */
        $builder = $model::query();
        /** @var Builder<TModel> $builder */
        $with = $this->resolveIncludes($repositoryQuery);
        $this->applyIncludes($builder, $with);

        return $builder;
    }

    /**
     * @param  Builder<TModel>  $builder
     * @param  list<string>  $includes
     */
    protected function applyIncludes(mixed $builder, array $includes): void
    {
        if (! $builder instanceof Builder) {
            return;
        }

        if ($includes === []) {
            return;
        }

        $builder->with($includes);
    }

    /**
     * @return list<string>
     */
    protected function resolveIncludes(RepositoryQuery $repositoryQuery): array
    {
        $includes = $repositoryQuery->includes();
        if ($includes === []) {
            $includes = $repositoryQuery->filters()['include'] ?? [];
            if (is_string($includes)) {
                $includes = explode(',', $includes);
            }
        }

        if (! is_array($includes)) {
            return [];
        }

        $allowed = $this->queryDefinition()->allowedIncludes();
        if ($allowed === []) {
            return [];
        }

        $resolved = [];
        foreach ($includes as $include) {
            if (! is_string($include) || $include === '') {
                continue;
            }

            $include = trim($include);

            if (in_array($include, $allowed, true)) {
                $resolved[] = $include;
            }

            $parts = explode('.', $include);
            $relationPath = '';
            foreach ($parts as $part) {
                $relationPath = $relationPath !== '' && $relationPath !== '0' ? "{$relationPath}.{$part}" : $part;
                if (in_array($relationPath, $allowed, true)) {
                    $resolved[] = $relationPath;
                }
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @param  Builder<TModel>  $builder
     */
    protected function applyFilters(mixed $builder, RepositoryQuery $repositoryQuery, QueryDefinition $queryDefinition): void
    {
        if (! $builder instanceof Builder) {
            return;
        }

        foreach ($repositoryQuery->filters() as $key => $value) {
            if (in_array($key, ['limit', 'page', 'include', 'sort', 'fields', 'context'], true)) {
                continue;
            }

            $this->applyFilter($builder, (string) $key, $value, $queryDefinition);
        }

        $this->applySorting($builder, $repositoryQuery->sort(), $queryDefinition);
    }

    /**
     * @param  Builder<Model>  $builder
     */
    protected function applyFilter(mixed $builder, string $key, mixed $value, QueryDefinition $queryDefinition): void
    {
        if (! $builder instanceof Builder) {
            return;
        }

        if (
            interface_exists(FilterInterface::class) &&
            $value instanceof FilterInterface
        ) {
            $value->apply($builder, $key);

            return;
        }

        if (is_array($value) && $this->isFilterArray($value)) {
            $this->applyFilterArray($builder, $key, $value, $queryDefinition);

            return;
        }

        if (str_contains($key, '.')) {
            $this->applyRelationFilter($builder, $key, $value, $queryDefinition);

            return;
        }

        if (preg_match('/^(.+)\[(.+)\]$/', $key, $matches)) {
            $field = (string) $matches[1];
            $operator = (string) $matches[2];
            if ($queryDefinition->allowsFilter($field) && isset($queryDefinition->operators()[$operator])) {
                $this->applyOperatorFilter($builder, $field, $operator, $value, $queryDefinition);
            }

            return;
        }

        if ($queryDefinition->allowsFilter($key)) {
            $this->applyStandardFilter($builder, $key, $value);
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    protected function isFilterArray(array $value): bool
    {
        return isset($value['operator']) || isset($value['value']) ||
            array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * @param  Builder<Model>  $builder
     * @param  array<string, mixed>  $filterConfig
     */
    protected function applyFilterArray(Builder $builder, string $key, array $filterConfig, QueryDefinition $queryDefinition): void
    {
        $operator = (string) ($filterConfig['operator'] ?? $queryDefinition->defaultOperator());
        $value = $filterConfig['value'] ?? $filterConfig;

        if ($queryDefinition->allowsFilter($key) && isset($queryDefinition->operators()[$operator])) {
            $this->applyOperatorFilter($builder, $key, $operator, $value, $queryDefinition);
        }
    }

    /**
     * @param  Builder<Model>  $builder
     */
    protected function applyRelationFilter(mixed $builder, string $key, mixed $value, QueryDefinition $queryDefinition): void
    {
        if (! $builder instanceof Builder) {
            return;
        }

        [$relation, $field] = explode('.', $key, 2);

        if (! $queryDefinition->allowsRelation($relation)) {
            return;
        }

        $allowedFields = $queryDefinition->allowedRelationFields($relation);
        if ($allowedFields !== null && ! in_array($field, $allowedFields, true)) {
            return;
        }

        $builder->whereHas($relation, function (Builder $builder) use ($field, $value, $queryDefinition): void {
            $this->applyFilter($builder, $field, $value, $queryDefinition);
        });
    }

    /**
     * @param  Builder<Model>  $builder
     */
    protected function applyOperatorFilter(mixed $builder, string $field, string $operator, mixed $value, QueryDefinition $queryDefinition): void
    {
        if (! $builder instanceof Builder) {
            return;
        }

        $operators = $queryDefinition->operators();
        $sqlOperator = $operators[$operator] ?? $operator;

        switch ($operator) {
            case 'in':
            case 'not_in':
                if (is_string($value)) {
                    $value = array_map(trim(...), explode(',', $value));
                }
                if (is_array($value)) {
                    $method = $operator === 'in' ? 'whereIn' : 'whereNotIn';
                    $builder->{$method}($field, $value);
                }
                break;

            case 'null':
                $builder->whereNull($field);
                break;

            case 'not_null':
                $builder->whereNotNull($field);
                break;

            case 'between':
                if (is_string($value)) {
                    $value = array_map(trim(...), explode(',', $value));
                }
                if (is_array($value) && count($value) === 2) {
                    $builder->whereBetween($field, $value);
                }
                break;

            case 'like':
            case 'not_like':
                $value = str_replace('*', '%', (string) $value);
                $builder->where($field, $sqlOperator, $value);
                break;

            default:
                $builder->where($field, $sqlOperator, $value);
                break;
        }
    }

    /**
     * @param  Builder<Model>  $builder
     */
    protected function applyStandardFilter(mixed $builder, string $field, mixed $value): void
    {
        if (! $builder instanceof Builder) {
            return;
        }

        if (is_array($value)) {
            $builder->whereIn($field, $value);
        } elseif (is_string($value) && str_contains($value, ',')) {
            $builder->whereIn($field, array_map(trim(...), explode(',', $value)));
        } else {
            $builder->where($field, $value);
        }
    }

    /**
     * @param  Builder<TModel>  $builder
     * @param  array<int, string>  $sort
     */
    protected function applySorting(mixed $builder, array $sort, QueryDefinition $queryDefinition): void
    {
        if (! $builder instanceof Builder) {
            return;
        }

        if ($sort === []) {
            return;
        }

        foreach ($sort as $segment) {
            if (! is_string($segment) || $segment === '') {
                continue;
            }

            $direction = str_starts_with($segment, '-') ? 'desc' : 'asc';
            $field = ltrim($segment, '+-');

            if (str_contains($field, '.')) {
                $this->applyRelationSorting($builder, $field, $direction, $queryDefinition);

                continue;
            }

            if ($queryDefinition->allowsFilter($field)) {
                $builder->orderBy($field, $direction);
            }
        }
    }

    /**
     * @param  Builder<TModel>  $builder
     */
    protected function applyRelationSorting(mixed $builder, string $sortField, string $direction, QueryDefinition $queryDefinition): void
    {
        if (! $builder instanceof Builder) {
            return;
        }

        $parts = explode('.', $sortField);

        if (count($parts) !== 2) {
            return;
        }

        [$relation, $field] = $parts;
        if (! $queryDefinition->allowsRelation($relation)) {
            return;
        }

        $builder->with([$relation => function ($relationQuery) use ($field, $direction): void {
            $relationQuery->orderBy($field, $direction);
        }]);
    }

    /**
     * @param  Builder<TModel>  $builder
     * @param  list<string>  $fields
     */
    protected function applyFieldSelection(mixed $builder, array $fields): void
    {
        if (! $builder instanceof Builder) {
            return;
        }

        if ($fields === []) {
            return;
        }

        $builder->select($fields);
    }

    /**
     * @param  Builder<TModel>  $builder
     */
    protected function applyLimit(mixed $builder, ?int $limit): void
    {
        if (! $builder instanceof Builder) {
            return;
        }

        if ($limit !== null && $limit > 0) {
            $builder->limit($limit);
        }
    }

    /**
     * @return iterable<TModel>
     */
    protected function fetchAll(mixed $query): iterable
    {
        return $query->get();
    }

    protected function fetchFirst(mixed $query): mixed
    {
        return $query->first();
    }

    protected function fetchById(string|int $id, array $include): mixed
    {
        $model = $this->modelClass();
        $idValue = $this->resolveId($id);

        return $model::with($include)->find($idValue);
    }

    protected function fetchPaginated(mixed $query, int $page, int $limit): Pagination
    {
        $lengthAwarePaginator = $query->paginate($limit, ['*'], 'page', $page);

        return new Pagination(
            items: $lengthAwarePaginator->items(),
            total: $lengthAwarePaginator->total(),
            perPage: $lengthAwarePaginator->perPage(),
            currentPage: $lengthAwarePaginator->currentPage(),
            lastPage: $lengthAwarePaginator->lastPage(),
        );
    }

    /**
     * @return LazyCollection<int, TModel>
     */
    protected function fetchCursor(mixed $query): iterable
    {
        return $query->cursor();
    }

    protected function fetchCount(mixed $query): int
    {
        return (int) $query->count();
    }

    public function clearAllCache(): void
    {
        try {
            $this->cache()->flushAll();
        } catch (\Throwable) {
            // fall back to legacy behaviour for non-supported stores
        }
    }

    /**
     * @return class-string<TModel>
     */
    abstract protected function modelClass(): string;
}
