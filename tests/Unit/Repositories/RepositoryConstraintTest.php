<?php

declare(strict_types=1);

namespace Zolta\Tests\Unit\Repositories;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zolta\Cqrs\Repositories\Query\RepositoryConstraint;
use Zolta\Cqrs\Repositories\Query\RepositoryQuery;

final class RepositoryConstraintTest extends TestCase
{
    public function test_constraints_are_added_programmatically_and_preserved_by_immutable_copies(): void
    {
        $query = RepositoryQuery::fromOptions([
            'filters' => ['status' => 'saved'],
            'context' => ['request_id' => 'request-1'],
        ])->withConstraint(RepositoryConstraint::equals('user_id', 'user-1'));

        $copy = $query->withContext(['trace_id' => 'trace-1']);

        $this->assertSame(['status' => 'saved'], $copy->filters());
        $this->assertSame(['request_id' => 'request-1', 'trace_id' => 'trace-1'], $copy->context());
        $this->assertSame([
            ['field' => 'user_id', 'operator' => 'eq', 'value' => 'user-1'],
        ], array_map(static fn (RepositoryConstraint $constraint): array => $constraint->toArray(), $copy->constraints()));
    }

    public function test_raw_constraint_arrays_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RepositoryQuery::fromOptions([
            'constraints' => [['field' => 'user_id', 'operator' => 'eq', 'value' => 'attacker']],
        ]);
    }

    public function test_constraint_fields_and_values_are_validated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RepositoryConstraint::equals('users.id', 'user-1');
    }
}
