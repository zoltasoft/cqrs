<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Repositories\Query\Interfaces;

/**
 * Declarative description of what a repository query may touch.
 *
 * - allowedFilters: scalar fields on the aggregate root
 * - relationFilters: map relation => allowed fields (null means all)
 * - allowedIncludes: relations that can be eagerly loaded
 * - operators: supported operator aliases (eq, gte, like, etc.)
 * - defaultOperator: fallback operator when not provided in structured filters
 */
final class QueryDefinition
{
    /**
     * @param  list<string>  $allowedIncludes
     * @param  list<string>  $allowedFilters
     * @param  array<string, list<string>|null>  $relationFilters
     * @param  array<string, string>  $operators
     * @param  list<string>  $allowedConstraintFields
     */
    public function __construct(
        private readonly array $allowedIncludes = [],
        private readonly array $allowedFilters = [],
        private array $relationFilters = [],
        private readonly array $operators = [],
        private readonly string $defaultOperator = 'eq',
        private readonly array $allowedConstraintFields = [],
    ) {}

    /**
     * @return list<string>
     */
    public function allowedIncludes(): array
    {
        return $this->allowedIncludes;
    }

    /**
     * @return list<string>
     */
    public function allowedFilters(): array
    {
        return $this->allowedFilters;
    }

    /**
     * @return array<string, list<string>|null>
     */
    public function relationFilters(): array
    {
        return $this->relationFilters;
    }

    /**
     * @return array<string,string>
     */
    public function operators(): array
    {
        return $this->operators;
    }

    public function defaultOperator(): string
    {
        return $this->defaultOperator;
    }

    public function allowsFilter(string $field): bool
    {
        return in_array($field, $this->allowedFilters, true);
    }

    /** @return list<string> */
    public function allowedConstraintFields(): array
    {
        return $this->allowedConstraintFields;
    }

    public function allowsConstraint(string $field): bool
    {
        return in_array($field, $this->allowedConstraintFields, true);
    }

    public function allowsRelation(string $relation): bool
    {
        return in_array($relation, $this->allowedIncludes, true)
            || array_key_exists($relation, $this->relationFilters);
    }

    /**
     * @return array<int, string>|null
     */
    public function allowedRelationFields(string $relation): ?array
    {
        return $this->relationFilters[$relation] ?? null;
    }
}
