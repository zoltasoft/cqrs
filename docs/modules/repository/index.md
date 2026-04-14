---
title: Repository
description: Persistence abstraction with query definitions, filtering, sorting, and Eloquent integration.
navigation:
  title: Repository
  order: 5
---

# Repository

Zolta CQRS provides a layered repository abstraction: a framework-agnostic `AbstractRepository` base with pluggable query building, and an Eloquent adapter for Laravel.

## Architecture

```
AbstractRepository (framework-agnostic)
    ├─ QueryDefinition (allowed filters, includes, operators)
    ├─ RepositoryQuery (structured query object)
    ├─ RepositoryCache (optional caching layer)
    └─ Framework-specific implementation
         └─ EloquentBaseRepository (Laravel)
              └─ Eloquent Builder
```

## AbstractRepository

The base class all repositories extend:

```php
namespace Zolta\Cqrs\Repositories\Query\Services;

abstract class AbstractRepository
{
    // Configuration (override in subclasses)
    protected array $allowedFilters = [];
    protected array $filterOperators = [];
    protected array $allowedRelations = [];
    protected array $filterableRelations = [];
    protected string $defaultOperator = 'eq';
    protected int $cacheTtlMinutes = 5;
    protected int $cacheTtlSeconds = 0;
    protected bool $enableReadCaching = false;
    protected bool $useTaggedCache = false;

    // Public API
    public function all($opts = null): iterable;
    public function first($opts = null): mixed;
    public function show(string|int $id, array $include = []): mixed;
    public function paginate($opts = null): mixed;
    public function cursor($opts = null): iterable;
    public function count($opts = null): int;
    public function repositoryQuery($source = null): RepositoryQuery;

    // Abstract methods (implemented by adapter)
    abstract protected function buildQuery(RepositoryQuery $rq): mixed;
    abstract protected function cache(): RepositoryCache;
    abstract protected function fetchAll(mixed $query): iterable;
    abstract protected function fetchFirst(mixed $query): mixed;
    abstract protected function fetchById(string|int $id, array $with = []): mixed;
    abstract protected function fetchPaginated(mixed $query, int $page, int $limit): mixed;
    abstract protected function fetchCursor(mixed $query): iterable;
    abstract protected function fetchCount(mixed $query): int;
    abstract protected function applyFilters(mixed $builder, RepositoryQuery $rq, QueryDefinition $qd): void;
    abstract protected function applySorting(mixed $builder, array $sort, QueryDefinition $qd): void;
    abstract protected function applyFieldSelection(mixed $builder, array $fields): void;
    abstract protected function applyLimit(mixed $builder, ?int $limit): void;
    abstract protected function modelClass(): string;
}
```

### Configuration properties

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$allowedFilters` | `array` | `[]` | Whitelist of filterable fields |
| `$filterOperators` | `array` | `[]` | Custom operator mappings (e.g., `'like' => 'LIKE'`) |
| `$allowedRelations` | `array` | `[]` | Relations allowed for eager loading |
| `$filterableRelations` | `array` | `[]` | Relations and their filterable fields |
| `$defaultOperator` | `string` | `'eq'` | Default filter comparison operator |
| `$cacheTtlMinutes` | `int` | `5` | Cache TTL in minutes |
| `$cacheTtlSeconds` | `int` | `0` | Cache TTL in seconds (overrides minutes if > 0) |
| `$enableReadCaching` | `bool` | `false` | Enable automatic read caching |
| `$useTaggedCache` | `bool` | `false` | Use tagged cache for grouped invalidation |

## QueryDefinition

Defines the query rules for a repository:

```php
namespace Zolta\Cqrs\Repositories\Query\Interfaces;

class QueryDefinition
{
    public function __construct(
        array $allowedIncludes = [],
        array $allowedFilters = [],
        array $relationFilters = [],
        array $operators = [],
        string $defaultOperator = 'eq',
    );

    public function allowedIncludes(): array;
    public function allowedFilters(): array;
    public function relationFilters(): array;
    public function operators(): array;
    public function defaultOperator(): string;
    public function allowsFilter(string $field): bool;
    public function allowsRelation(string $relation): bool;
    public function allowedRelationFields(string $relation): ?array;
}
```

### Configuring query definitions

Override `queryDefinition()` in your repository:

```php
protected function queryDefinition(): QueryDefinition
{
    return new QueryDefinition(
        allowedIncludes: ['roles', 'profile', 'permissions'],
        allowedFilters: ['name', 'email', 'status', 'created_at'],
        relationFilters: [
            'roles' => ['name', 'level'],
            'profile' => null, // All fields allowed
        ],
        operators: [
            'name' => 'like',
            'created_at' => 'gte',
        ],
        defaultOperator: 'eq',
    );
}
```

## RepositoryQuery

A structured, immutable query object:

```php
namespace Zolta\Cqrs\Repositories\Query;

class RepositoryQuery
{
    public function __construct(
        array $filters = [],
        array $includes = [],
        array $sort = [],
        ?int $limit = null,
        ?int $page = null,
        array $fields = [],
        array $context = [],
    );

    public static function fromOptions(AbstractQueryOptions|array|null $source): self;

    public function filters(): array;
    public function includes(): array;
    public function sort(): array;
    public function limit(): ?int;
    public function page(): ?int;
    public function fields(): array;
    public function context(): array;
}
```

## QueryOptions

A convenient payload container for passing query parameters:

```php
namespace Zolta\Cqrs\Repositories\Query;

class QueryOptions extends AbstractQueryOptions
{
    public function __construct(array $payload = []);
    public function toArray(): array;
}
```

Usage:

```php
$options = new QueryOptions([
    'filters' => ['status' => 'active', 'name' => 'John'],
    'includes' => ['roles', 'profile'],
    'sort' => ['-created_at', 'name'],  // '-' prefix = descending
    'limit' => 20,
    'page' => 1,
    'fields' => ['id', 'name', 'email'],
]);
```

## EloquentBaseRepository

The Laravel Eloquent implementation:

```php
namespace Zolta\Cqrs\Adapters\Laravel\Eloquent;

abstract class EloquentBaseRepository extends AbstractRepository
{
    // Builds Eloquent queries with:
    // - Eager loading (with())
    // - Where clauses from filters
    // - Order by from sort
    // - Field selection (select())
    // - Limit/offset for pagination

    protected function buildQuery(RepositoryQuery $rq): Builder;
    protected function cache(): RepositoryCache;
    protected function cacheKeyGenerator(): CacheKeyGenerator;
    protected function cacheTag(): string;
    protected function cacheNamespace(string $segment): string;
}
```

### Creating an Eloquent repository

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Domain\Aggregates\User;
use App\Domain\Repositories\UserRepositoryInterface;
use Zolta\Cqrs\Adapters\Laravel\Eloquent\EloquentBaseRepository;
use Zolta\Cqrs\Repositories\Query\Interfaces\QueryDefinition;

class EloquentUserRepository extends EloquentBaseRepository implements UserRepositoryInterface
{
    protected array $allowedFilters = ['name', 'email', 'status'];
    protected array $allowedRelations = ['roles', 'profile'];
    protected bool $enableReadCaching = true;
    protected int $cacheTtlMinutes = 10;

    protected function modelClass(): string
    {
        return User::class;
    }

    protected function queryDefinition(): QueryDefinition
    {
        return new QueryDefinition(
            allowedIncludes: ['roles', 'profile', 'permissions'],
            allowedFilters: ['name', 'email', 'status', 'role'],
            relationFilters: [
                'roles' => ['name'],
            ],
            operators: [
                'name' => 'like',
            ],
        );
    }

    public function findById(string $id): ?User
    {
        return $this->show($id);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->first(new QueryOptions([
            'filters' => ['email' => $email],
        ]));
    }

    public function save(User $user): void
    {
        // Persist via Eloquent
        $model = UserModel::updateOrCreate(
            ['id' => (string) $user->id],
            $user->toArray(),
        );
    }
}
```

## Filters

### Built-in filters

#### FilterInterface

```php
namespace Zolta\Cqrs\Repositories\Filters;

interface FilterInterface
{
    // Marker interface for custom filter implementations
}
```

#### DateRangeFilter

Pre-built filter for date range queries:

```php
use Zolta\Cqrs\Repositories\Filters\DateRangeFilter;

// Usage in repository configuration
protected array $filterOperators = [
    'created_at' => 'date_range',
];
```

#### SearchFilter

Pre-built filter for text search across multiple columns:

```php
use Zolta\Cqrs\Repositories\Filters\SearchFilter;
```

### Filter operators

Default operators supported:

| Operator | SQL | Example |
|----------|-----|---------|
| `eq` | `=` | `['status' => 'active']` |
| `neq` | `!=` | `['status[neq]' => 'deleted']` |
| `gt` | `>` | `['age[gt]' => 18]` |
| `gte` | `>=` | `['age[gte]' => 18]` |
| `lt` | `<` | `['score[lt]' => 100]` |
| `lte` | `<=` | `['score[lte]' => 100]` |
| `like` | `LIKE` | `['name[like]' => '%john%']` |
| `in` | `IN` | `['status[in]' => ['active', 'pending']]` |

## Usage examples

### All records

```php
$users = $repository->all();
```

### First matching record

```php
$user = $repository->first(new QueryOptions([
    'filters' => ['email' => 'john@example.com'],
]));
```

### By ID with relations

```php
$user = $repository->show('123', include: ['roles', 'profile']);
```

### Paginated

```php
$page = $repository->paginate(new QueryOptions([
    'page' => 1,
    'limit' => 20,
    'filters' => ['status' => 'active'],
    'sort' => ['-created_at'],
    'includes' => ['roles'],
]));
```

### Cursor streaming

```php
foreach ($repository->cursor() as $user) {
    // Process each user without loading all into memory
}
```

### Count

```php
$count = $repository->count(new QueryOptions([
    'filters' => ['status' => 'active'],
]));
```
