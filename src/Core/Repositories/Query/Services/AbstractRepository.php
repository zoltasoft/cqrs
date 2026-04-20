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

    protected int $cacheTtlMinutes = 5;

    /**
     * Override in subclasses for sub-minute precision (takes precedence over $cacheTtlMinutes).
     */
    protected int $cacheTtlSeconds = 0;

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
     * Build a RepositoryQuery from various sources.
     */
    /**
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
        $query = $this->buildQuery($repositoryQuery);

        if (! $this->enableReadCaching) {
            return $this->fetchAll($query);
        }

        return $this->cache()->remember(
            'all',
            [
                'filters' => $repositoryQuery->filters(),
                'include' => $repositoryQuery->includes(),
                'sort' => $repositoryQuery->sort(),
            ],
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
        $query = $this->buildQuery($repositoryQuery);

        if (! $this->enableReadCaching) {
            return $this->fetchFirst($query);
        }

        return $this->cache()->remember(
            'first',
            [
                'filters' => $repositoryQuery->filters(),
                'include' => $repositoryQuery->includes(),
                'sort' => $repositoryQuery->sort(),
            ],
            fn(): mixed => $this->fetchFirst($query),
            $this->cacheTtlSeconds()
        );
    }

    /**
     * @param  array<int,string>  $include
     */
    public function show(string|int $id, array $include = []): mixed
    {
        $with = array_values(array_intersect($include, $this->getAllowedRelations()));
        sort($with);

        if (! $this->enableReadCaching) {
            return $this->fetchById($id, $with);
        }

        return $this->cache()->remember(
            'entity',
            [
                'id' => (string) $id,
                'includes' => $with,
            ],
            fn(): mixed => $this->fetchById($id, $with),
            $this->cacheTtlSeconds()
        );
    }

    /**
     * @return mixed pagination payload (framework-specific)
     */
    /**
     * @param  array<string,mixed>|RepositoryQuery|AbstractQueryOptions|null  $opts
     * @return mixed pagination payload (framework-specific)
     */
    public function paginate(AbstractQueryOptions|RepositoryQuery|array|null $opts = null): mixed
    {
        $repositoryQuery = $this->repositoryQuery($opts);
        $query = $this->buildQuery($repositoryQuery);

        $limit = $repositoryQuery->limit() ?? 25;
        $page = $repositoryQuery->page() ?? 1;

        return $this->fetchPaginated($query, $page, $limit);
    }

    /**
     * @param  array<string,mixed>|RepositoryQuery|AbstractQueryOptions|null  $opts
     * @return iterable<mixed>
     */
    public function cursor(AbstractQueryOptions|RepositoryQuery|array|null $opts = null): iterable
    {
        $repositoryQuery = $this->repositoryQuery($opts);
        $query = $this->buildQuery($repositoryQuery);

        return $this->fetchCursor($query);
    }

    /**
     * @param  array<string,mixed>|RepositoryQuery|AbstractQueryOptions|null  $opts
     */
    public function count(AbstractQueryOptions|RepositoryQuery|array|null $opts = null): int
    {
        $repositoryQuery = $this->repositoryQuery($opts);
        $query = $this->buildQuery($repositoryQuery);

        return $this->fetchCount($query);
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
        if ($this->cacheTtlSeconds > 0) {
            return $this->cacheTtlSeconds;
        }

        return max(1, $this->cacheTtlMinutes * 60);
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
     */
    /**
     * @param  array<int,string>  $includes
     */
    abstract protected function applyIncludes(mixed $query, array $includes): void;

    /**
     * Apply sorting.
     */
    /**
     * @param  array<int,string>  $sort
     */
    abstract protected function applySorting(mixed $query, array $sort, QueryDefinition $queryDefinition): void;

    /**
     * Apply field selection.
     */
    /**
     * @param  array<int,string>  $fields
     */
    abstract protected function applyFieldSelection(mixed $query, array $fields): void;

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
