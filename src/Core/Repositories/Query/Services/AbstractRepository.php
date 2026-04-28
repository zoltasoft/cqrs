<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Repositories\Query\Services;

use Zolta\Cqrs\Repositories\Cache\RepositoryCache;
use Zolta\Cqrs\Repositories\Query\Interfaces\QueryDefinition;
use Zolta\Cqrs\Repositories\Query\RepositoryQuery;
use Zolta\Domain\Repositories\Query\AbstractQueryOptions;

/**
 * Framework-agnostic repository contract that encapsulates query/options handling,
 * filters/includes/sorts application, pagination, streaming, and cache hooks.
 *
 * Concrete framework adapters (Eloquent/Doctrine) implement the abstract hooks.
 */
abstract class AbstractRepository
{
    /** @var list<string> */
    protected array $allowedFilters = [];

    /** @var array<string,string> */
    protected array $filterOperators = [];

    /** @var list<string> */
    protected array $allowedRelations = [];

    /** @var array<string, list<string>|null> */
    protected array $filterableRelations = [];

    protected string $defaultOperator = 'eq';


    /**
     * Cache TTL in seconds (default 300s = 5min).
     */
    protected int $cacheTtlSeconds = 300;

    /**
     * Cache tag/namespace for this repository (override in concrete repos).
     */
    protected string $cacheTag = 'repository';

    protected bool $enableReadCaching = false;

    /**
     * Tagged cache is only supported in some frameworks (Laravel).
     */
    protected bool $useTaggedCache = false;

    /** @var array<string,string> */
    protected static array $defaultFilterOperators = [
        'eq' => '=',
        'ne' => '!=',
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
        'like' => 'like',
        'not_like' => 'not like',
        'in' => 'in',
        'not_in' => 'not in',
        'null' => 'null',
        'not_null' => 'not null',
        'between' => 'between',
    ];

    /**
     * Pipeline orchestration: applies filters, includes, sorting, fields, limit.
     * Adapters should call this after buildQuery.
     */
    protected function finalizeQuery(mixed $query, RepositoryQuery $rq): mixed
    {
        $definition = $this->queryDefinition();
        $this->applyFilters($query, $rq, $definition);
        $this->applyIncludes($query, $rq->includes());
        $this->applySorting($query, $rq->sort(), $definition);
        $this->applyFieldSelection($query, $rq->fields());
        $this->applyLimit($query, $rq->limit());
        return $query;
    }

    /**
     * Write-invalidation hook: bust all cache for this repository.
     */
    protected function bustCache(): void
    {
        if (! $this->enableReadCaching) {
            return;
        }
        $this->cache()->flushAll();
    }

    /**
     * Unified query creation helper.
     * @param array|RepositoryQuery|AbstractQueryOptions|null $source
     */
    protected function query(array|RepositoryQuery|AbstractQueryOptions|null $source = null): RepositoryQuery
    {
        return $this->repositoryQuery($source);
    }

    // /**
    //  * Query factory short helper.
    //  */
    // private function rq(array $opts = []): RepositoryQuery
    // {
    //     return $this->query($opts);
    // }

    /**
     * Build a RepositoryQuery from various sources.
     *
     * @param  array<string,mixed>|RepositoryQuery|AbstractQueryOptions|null  $source
     */
    public function repositoryQuery(AbstractQueryOptions|RepositoryQuery|array|null $source = null): RepositoryQuery
    {
        if ($source instanceof RepositoryQuery) {
            return $source;
        }

        return RepositoryQuery::fromOptions($source);
    }

    /**
     * Repositories can override to fine-tune their query definition.
     */
    protected function queryDefinition(): QueryDefinition
    {
        return new QueryDefinition(
            allowedIncludes: $this->getAllowedRelations(),
            allowedFilters: $this->allowedFilters,
            relationFilters: $this->filterableRelations,
            operators: $this->getFilterOperators(),
            defaultOperator: $this->defaultOperator,
        );
    }

    /**
     * Merge repository-specific operator overrides with default canonical operators.
     *
     * @return array<string,string>
     */
    protected function getFilterOperators(): array
    {
        return array_merge(static::$defaultFilterOperators, $this->filterOperators);
    }

    // ---- Public operations built on top of abstract hooks ----

    /**
     * @param  array<string,mixed>|RepositoryQuery|AbstractQueryOptions|null  $opts
     * @return iterable<mixed>
     */
    public function all(AbstractQueryOptions|RepositoryQuery|array|null $opts = null): iterable
    {
        $repositoryQuery = $this->repositoryQuery($opts);
        $query = $this->finalizeQuery($this->buildQuery($repositoryQuery), $repositoryQuery);

        $cacheKey = [
            'filters' => $repositoryQuery->filters(),
            'include' => $repositoryQuery->includes(),
            'sort' => $repositoryQuery->sort(),
            'fields' => $repositoryQuery->fields(),
            'limit' => $repositoryQuery->limit(),
            'page' => $repositoryQuery->page(),
        ];

        if (! $this->enableReadCaching) {
            return $this->fetchAll($query);
        }

        return $this->cache()->remember(
            'all',
            $cacheKey,
            fn(): iterable => $this->fetchAll($query),
            $this->cacheTtlSeconds()
        );
    }

    /**
     * @param  array<string,mixed>|RepositoryQuery|AbstractQueryOptions|null  $opts
     */
    public function first(AbstractQueryOptions|RepositoryQuery|array|null $opts = null): mixed
    {
        $repositoryQuery = $this->repositoryQuery($opts);
        $query = $this->finalizeQuery($this->buildQuery($repositoryQuery), $repositoryQuery);

        $cacheKey = [
            'filters' => $repositoryQuery->filters(),
            'include' => $repositoryQuery->includes(),
            'sort' => $repositoryQuery->sort(),
            'fields' => $repositoryQuery->fields(),
            'limit' => $repositoryQuery->limit(),
            'page' => $repositoryQuery->page(),
        ];

        if (! $this->enableReadCaching) {
            return $this->fetchFirst($query);
        }

        return $this->cache()->remember(
            'first',
            $cacheKey,
            fn(): mixed => $this->fetchFirst($query),
            $this->cacheTtlSeconds()
        );
    }

    /**
     * @param  array<int,string>  $include
     */
    public function show(string|int $id, array $include = []): mixed
    {
        $definition = $this->queryDefinition();
        $with = array_values(array_intersect($include, $definition->allowedIncludes()));
        sort($with);

        $cacheKey = [
            'id' => (string) $id,
            'includes' => $with,
        ];

        if (! $this->enableReadCaching) {
            return $this->fetchById($id, $with);
        }

        return $this->cache()->remember(
            'entity',
            $cacheKey,
            fn(): mixed => $this->fetchById($id, $with),
            $this->cacheTtlSeconds()
        );
    }

    /**
     * Write contract: save an aggregate (create or update).
     * Must be implemented by adapters and call bustCache after write.
     */
    // abstract public function save(object $aggregate): void;

    /**
     * Write contract: delete by id.
     * Must be implemented by adapters and call bustCache after write.
     */
    // abstract public function delete(string|int $id): void;

    /**
     * @param  array<string,mixed>|RepositoryQuery|AbstractQueryOptions|null  $opts
     * @return mixed pagination payload (framework-specific)
     */
    public function paginate(AbstractQueryOptions|RepositoryQuery|array|null $opts = null): mixed
    {
        $repositoryQuery = $this->repositoryQuery($opts);
        $query = $this->finalizeQuery($this->buildQuery($repositoryQuery), $repositoryQuery);

        $limit = $repositoryQuery->limit() ?? 25;
        $page  = $repositoryQuery->page() ?? 1;

        $cacheKey = [
            'filters' => $repositoryQuery->filters(),
            'include' => $repositoryQuery->includes(),
            'sort'    => $repositoryQuery->sort(),
            'fields'  => $repositoryQuery->fields(),
            'limit'   => $limit,
            'page'    => $page,
        ];

        if (! $this->enableReadCaching) {
            return $this->fetchPaginated($query, $page, $limit);
        }

        return $this->cache()->remember(
            'paginate',
            $cacheKey,
            fn(): mixed => $this->fetchPaginated($query, $page, $limit),
            $this->cacheTtlSeconds()
        );
    }

    /**
     * Streams results directly from the database.
     * Deliberately not cached — cursors are lazy and incompatible with cache storage.
     *
     * @param  array<string,mixed>|RepositoryQuery|AbstractQueryOptions|null  $opts
     * @return iterable<mixed>
     */
    public function cursor(AbstractQueryOptions|RepositoryQuery|array|null $opts = null): iterable
    {
        $repositoryQuery = $this->repositoryQuery($opts);
        $query = $this->finalizeQuery($this->buildQuery($repositoryQuery), $repositoryQuery);

        return $this->fetchCursor($query);
    }

    /**
     * @param  array<string,mixed>|RepositoryQuery|AbstractQueryOptions|null  $opts
     */
    public function count(AbstractQueryOptions|RepositoryQuery|array|null $opts = null): int
    {
        $repositoryQuery = $this->repositoryQuery($opts);
        $query = $this->buildQuery($repositoryQuery);

        $cacheKey = [
            'filters' => $repositoryQuery->filters(),
        ];

        if (! $this->enableReadCaching) {
            return $this->fetchCount($query);
        }

        return $this->cache()->remember(
            'count',
            $cacheKey,
            fn(): int => $this->fetchCount($query),
            $this->cacheTtlSeconds()
        );
    }

    /**
     * @return list<string>
     */
    protected function getAllowedRelations(): array
    {
        return $this->allowedRelations;
    }

    protected function cacheTtlSeconds(): int
    {
        return $this->cacheTtlSeconds > 0 ? $this->cacheTtlSeconds : 300;
    }

    // ---- Abstract hooks implemented by framework adapters ----

    /**
     * @return mixed Framework-specific query object (Eloquent Builder, Doctrine QB, etc.)
     */
    abstract protected function buildQuery(RepositoryQuery $repositoryQuery): mixed;

    /**
     * Return a cache adapter.
     */
    abstract protected function cache(): RepositoryCache;

    /**
     * Apply filters to the query object.
     */
    abstract protected function applyFilters(mixed $query, RepositoryQuery $repositoryQuery, QueryDefinition $queryDefinition): void;

    /**
     * Apply includes/relations.
     *
     * @param  array<int,string>  $includes
     */
    abstract protected function applyIncludes(mixed $query, array $includes): void;

    /**
     * Apply sorting.
     *
     * @param  array<int,string>  $sort
     */
    abstract protected function applySorting(mixed $query, array $sort, QueryDefinition $queryDefinition): void;

    /**
     * Apply field selection.
     *
     * @param  array<int,string>  $fields
     */
    abstract protected function applyFieldSelection(mixed $query, array $fields): void;

    /**
     * Apply a limit/offset if provided.
     */
    /**
     * Apply a limit/offset if provided.
     */
    abstract protected function applyLimit(mixed $query, ?int $limit): void;

    /**
     * Fetch all results.
     *
     * @return iterable<mixed>
     */
    abstract protected function fetchAll(mixed $query): iterable;

    /**
     * Fetch first result.
     */
    abstract protected function fetchFirst(mixed $query): mixed;

    /**
     * Fetch one by id with includes.
     */
    /**
     * @param  array<int,string>  $include
     */
    abstract protected function fetchById(string|int $id, array $include): mixed;

    /**
     * Paginate results.
     */
    abstract protected function fetchPaginated(mixed $query, int $page, int $limit): mixed;

    /**
     * Stream results.
     *
     * @return iterable<mixed>
     */
    abstract protected function fetchCursor(mixed $query): iterable;

    /**
     * Count results.
     */
    abstract protected function fetchCount(mixed $query): int;
}
