<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Repositories\Query;

use InvalidArgumentException;

/**
 * A trusted, mandatory repository predicate created by application or infrastructure code.
 *
 * Constraints are deliberately separate from client-controlled filters and are always
 * applied with AND semantics.
 */
final readonly class RepositoryConstraint
{
    private const OPERATORS = ['eq', 'in', 'null', 'not_null'];

    /**
     * @param  scalar|list<scalar>|null  $value
     */
    public function __construct(
        private string $field,
        private string $operator,
        private mixed $value = null,
    ) {
        if (! preg_match('/^[A-Za-z_]\w*$/', $field)) {
            throw new InvalidArgumentException("Invalid repository constraint field [{$field}].");
        }

        if (! in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Unsupported repository constraint operator [{$operator}].");
        }

        if ($operator === 'eq' && ! is_scalar($value)) {
            throw new InvalidArgumentException('The eq constraint requires a scalar value.');
        }

        if ($operator === 'in' && (! is_array($value) || $value === [] || array_filter($value, is_scalar(...)) !== $value)) {
            throw new InvalidArgumentException('The in constraint requires a non-empty list of scalar values.');
        }

        if (in_array($operator, ['null', 'not_null'], true) && $value !== null) {
            throw new InvalidArgumentException("The {$operator} constraint does not accept a value.");
        }
    }

    public static function equals(string $field, string|int|float|bool $value): self
    {
        return new self($field, 'eq', $value);
    }

    /** @param non-empty-list<scalar> $values */
    public static function oneOf(string $field, array $values): self
    {
        return new self($field, 'in', array_values($values));
    }

    public static function isNull(string $field): self
    {
        return new self($field, 'null');
    }

    public static function isNotNull(string $field): self
    {
        return new self($field, 'not_null');
    }

    public function field(): string
    {
        return $this->field;
    }

    public function operator(): string
    {
        return $this->operator;
    }

    /** @return scalar|list<scalar>|null */
    public function value(): mixed
    {
        return $this->value;
    }

    /** @return array{field:string,operator:string,value:mixed} */
    public function toArray(): array
    {
        return ['field' => $this->field, 'operator' => $this->operator, 'value' => $this->value];
    }
}
