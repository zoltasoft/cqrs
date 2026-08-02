<?php

declare(strict_types=1);

namespace Zolta\Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use Zolta\Cqrs\Repositories\Query\Exceptions\InvalidQueryOptionException;
use Zolta\Cqrs\Repositories\Query\QueryOptionsFactory;

final class QueryOptionsFactorySecurityTest extends TestCase
{
    public function test_strict_mode_rejects_unknown_filters(): void
    {
        $this->expectException(InvalidQueryOptionException::class);
        $this->expectExceptionMessage('user_id');

        (new QueryOptionsFactory)->make([
            'strict' => true,
            'allowed_filters' => ['status'],
            'filters' => ['user_id' => 'attacker'],
        ]);
    }

    public function test_strict_mode_rejects_unknown_sorts(): void
    {
        $this->expectException(InvalidQueryOptionException::class);
        $this->expectExceptionMessage('user_id');

        (new QueryOptionsFactory)->make([
            'strict' => true,
            'allowed_sorts' => ['created_at'],
            'sort' => ['user_id'],
        ]);
    }

    public function test_strict_mode_preserves_supported_options(): void
    {
        $options = (new QueryOptionsFactory)->make([
            'strict' => true,
            'allowed_filters' => ['status'],
            'allowed_sorts' => ['created_at'],
            'filters' => ['status' => 'saved'],
            'sort' => ['-created_at'],
        ]);

        $this->assertSame(['status' => 'saved'], $options->getFilters());
        $this->assertSame(['-created_at'], $options->getSort());
    }
}
