<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Repositories\Query;

use Zolta\Domain\Repositories\Query\AbstractQueryOptions;

/**
 * Immutable representation of a repository query.
 *
 * Provides normalized accessors for filters, includes, sorting, pagination, field
 * selection and contextual flags. The goal is to keep domain-level concerns
 * independent from any concrete persistence technology.
 */
final readonly class RepositoryQuery
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $includes
     * @param  list<string>  $sort
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        private array $filters = [],
        private array $includes = [],
        private array $sort = [],
        private ?int $limit = null,
        private ?int $page = null,
        private array $fields = [],
        private array $context = [],
    ) {}

    /**
     * Build a RepositoryQuery from domain options, raw array payload or null.
     *
     * @param  AbstractQueryOptions|array<string,mixed>|null  $source
     */
    public static function fromOptions(AbstractQueryOptions|array|null $source): self
    {
        if ($source instanceof AbstractQueryOptions) {
            $payload = $source->toArray();
        } elseif (is_array($source)) {
            $payload = $source;
        } else {
            $payload = [];
        }

        $filters = self::normalizeFilters($payload['filters'] ?? []);

        $reservedKeys = ['filters', 'include', 'sort', 'limit', 'page', 'fields', 'context'];
        $topLevelFilters = array_diff_key($payload, array_flip($reservedKeys));
        if ($topLevelFilters !== []) {
            $filters = array_merge($filters, $topLevelFilters);
        }
        $include = self::normalizeStringList($payload['include'] ?? []);
        $sort = self::normalizeStringList($payload['sort'] ?? []);
        $fields = self::normalizeStringList($payload['fields'] ?? ($filters['fields'] ?? []));

        unset($filters['include'], $filters['sort'], $filters['fields']);

        $limit = self::normalizeNullableInt($payload['limit'] ?? null);
        $page = self::normalizeNullableInt($payload['page'] ?? null);

        $context = $payload['context'] ?? [];
        if (! is_array($context)) {
            $context = (array) $context;
        }

        return new self(
            filters: $filters,
            includes: $include,
            sort: $sort,
            limit: $limit,
            page: $page,
            fields: $fields,
            context: $context,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function filters(): array
    {
        return $this->filters;
    }

    /**
     * @return list<string>
     */
    public function includes(): array
    {
        return $this->includes;
    }

    /**
     * @param  list<string>  $fallback
     * @return list<string>
     */
    public function includeOr(array $fallback): array
    {
        return $this->includes !== [] ? $this->includes : $fallback;
    }

    /**
     * @return list<string>
     */
    public function sort(): array
    {
        return $this->sort;
    }

    public function limit(): ?int
    {
        return $this->limit;
    }

    public function page(): ?int
    {
        return $this->page;
    }

    /**
     * @return list<string>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @return array<string,mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function withContext(array $context): self
    {
        return new self(
            filters: $this->filters,
            includes: $this->includes,
            sort: $this->sort,
            limit: $this->limit,
            page: $this->page,
            fields: $this->fields,
            context: array_merge($this->context, $context),
        );
    }

    /**
     * Export to array for backwards compatibility with existing infra code.
     *
     * @return array{
     *     filters: array<string,mixed>,
     *     include: list<string>,
     *     sort: list<string>,
     *     limit: ?int,
     *     page: ?int,
     *     fields: list<string>,
     *     context: array<string,mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'filters' => $this->filters,
            'include' => $this->includes,
            'sort' => $this->sort,
            'limit' => $this->limit,
            'page' => $this->page,
            'fields' => $this->fields,
            'context' => $this->context,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeFilters(mixed $filters): array
    {
        if ($filters === null) {
            return [];
        }

        if (is_string($filters)) {
            $decoded = json_decode($filters, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            return [];
        }

        return is_array($filters) ? $filters : [];
    }

    /**
     * @return list<string>
     */
    private static function normalizeStringList(mixed $value): array
    {
        if ($value === null || $value === []) {
            return [];
        }

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($item): ?string => is_string($item) ? trim($item) : null,
            $value
        ), static fn (?string $item): bool => $item !== null && $item !== ''));
    }

    private static function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
