<?php

declare(strict_types=1);

namespace Zolta\Tests\Integration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Zolta\Cqrs\Laravel\Eloquent\EloquentBaseRepository;
use Zolta\Cqrs\Repositories\Query\Exceptions\InvalidRepositoryConstraintException;
use Zolta\Cqrs\Repositories\Query\Exceptions\InvalidRepositoryFilterException;
use Zolta\Cqrs\Repositories\Query\RepositoryConstraint;
use Zolta\Cqrs\Repositories\Query\RepositoryQuery;

final class OwnedRecord extends Model
{
    protected $table = 'owned_records';

    public $timestamps = false;

    protected $guarded = [];
}

/** @extends EloquentBaseRepository<OwnedRecord> */
final class OwnedRecordRepository extends EloquentBaseRepository
{
    protected array $allowedFilters = ['status'];

    protected array $allowedConstraintFields = ['owner_id'];

    protected bool $enableReadCaching = true;

    protected bool $useTaggedCache = false;

    protected function modelClass(): string
    {
        return OwnedRecord::class;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return iterable<OwnedRecord>
     */
    public function forOwner(string $ownerId, array $filters = []): iterable
    {
        return $this->all(RepositoryQuery::fromOptions([
            'filters' => $filters,
        ])->withConstraint(RepositoryConstraint::equals('owner_id', $ownerId)));
    }

    /** @return iterable<OwnedRecord> */
    public function run(RepositoryQuery $query): iterable
    {
        return $this->all($query);
    }
}

final class EloquentRepositoryConstraintTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('owned_records', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_id');
            $table->string('status');
        });

        OwnedRecord::query()->insert([
            ['id' => 1, 'owner_id' => 'owner-a', 'status' => 'saved'],
            ['id' => 2, 'owner_id' => 'owner-b', 'status' => 'saved'],
            ['id' => 3, 'owner_id' => 'owner-a', 'status' => 'archived'],
        ]);
    }

    public function test_mandatory_constraint_is_applied_with_optional_filters(): void
    {
        $records = collect((new OwnedRecordRepository)->forOwner('owner-a', ['status' => 'saved']));

        $this->assertSame([1], $records->pluck('id')->all());
    }

    public function test_constraint_values_are_part_of_the_cache_key(): void
    {
        $repository = new OwnedRecordRepository;

        $ownerA = collect($repository->forOwner('owner-a'))->pluck('id')->all();
        $ownerB = collect($repository->forOwner('owner-b'))->pluck('id')->all();

        $this->assertSame([1, 3], $ownerA);
        $this->assertSame([2], $ownerB);
    }

    public function test_undeclared_constraint_fails_closed(): void
    {
        $query = RepositoryQuery::fromOptions([])
            ->withConstraint(RepositoryConstraint::equals('account_id', 'account-a'));

        $this->expectException(InvalidRepositoryConstraintException::class);

        (new OwnedRecordRepository)->run($query);
    }

    public function test_filter_cannot_target_a_constrained_field(): void
    {
        $query = RepositoryQuery::fromOptions(['filters' => ['owner_id' => 'owner-b']])
            ->withConstraint(RepositoryConstraint::equals('owner_id', 'owner-a'));

        $this->expectException(InvalidRepositoryFilterException::class);

        (new OwnedRecordRepository)->run($query);
    }

    public function test_unknown_repository_filter_fails_closed(): void
    {
        $this->expectException(InvalidRepositoryFilterException::class);

        (new OwnedRecordRepository)->forOwner('owner-a', ['unknown' => 'value']);
    }
}
