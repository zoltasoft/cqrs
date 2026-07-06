<?php

declare(strict_types=1);

namespace Zolta\Tests\Unit\Hydration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zolta\Cqrs\Commands\Contracts\CommandInterface;
use Zolta\Cqrs\Hydration\DefaultMessageHydrator;
use Zolta\Cqrs\Queries\Contracts\QueryInterface;
use Zolta\Domain\ValueObjects\ValueObject;
use Zolta\Domain\ValueObjects\VOConstructionContext;

// ── Test DTOs ────────────────────────────────────────────────────────────

class TestSimpleCommand implements CommandInterface
{
    public function __construct(
        public readonly string $name,
        public readonly int $age,
    ) {}
}

class TestOptionalCommand implements CommandInterface
{
    public function __construct(
        public readonly string $name,
        public readonly string $role = 'user',
    ) {}
}

class TestSimpleQuery implements QueryInterface
{
    public function __construct(
        public readonly int $id,
    ) {}
}

class GenericDto
{
    public function __construct(
        public readonly string $label,
        public readonly float $price = 0.0,
    ) {}
}

class NoConstructorDto {}

class TestArrayValueObject extends ValueObject
{
    /**
     * @param  list<string>  $area
     */
    public function __construct(
        public string $displayName,
        public array $area = [],
        public ?VOConstructionContext $context = null,
    ) {
        parent::__construct();
    }
}

class TestNestedArrayValueObject extends ValueObject
{
    public function __construct(
        public TestArrayValueObject $location,
        public ?VOConstructionContext $context = null,
    ) {
        parent::__construct();
    }
}

class TestCommandWithArrayValueObject implements CommandInterface
{
    public function __construct(
        public readonly TestArrayValueObject $location,
    ) {}
}

// ── Tests ────────────────────────────────────────────────────────────────

final class DefaultMessageHydratorTest extends TestCase
{
    private DefaultMessageHydrator $hydrator;

    protected function setUp(): void
    {
        $this->hydrator = new DefaultMessageHydrator;
    }

    // ── Object passthrough ──────────────────────────────────────────────

    public function test_hydrate_returns_existing_object_unchanged(): void
    {
        $command = new TestSimpleCommand('Alice', 30);

        $result = $this->hydrator->hydrate($command);

        $this->assertSame($command, $result);
    }

    // ── Non-existent class ──────────────────────────────────────────────

    public function test_hydrate_throws_for_non_existent_class(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        $this->hydrator->hydrate('Non\Existent\ClassName', []); // @phpstan-ignore argument.type
    }

    // ── Command hydration ───────────────────────────────────────────────

    public function test_hydrate_command_from_constructor(): void
    {
        $result = $this->hydrator->hydrate(TestSimpleCommand::class, [
            'name' => 'Bob',
            'age' => 25,
        ]);

        $this->assertInstanceOf(TestSimpleCommand::class, $result);
        $this->assertSame('Bob', $result->name);
        $this->assertSame(25, $result->age);
    }

    public function test_hydrate_command_with_default_values(): void
    {
        $result = $this->hydrator->hydrate(TestOptionalCommand::class, [
            'name' => 'Charlie',
        ]);

        $this->assertInstanceOf(TestOptionalCommand::class, $result);
        $this->assertSame('Charlie', $result->name);
        $this->assertSame('user', $result->role);
    }

    public function test_hydrate_command_overrides_defaults(): void
    {
        $result = $this->hydrator->hydrate(TestOptionalCommand::class, [
            'name' => 'Dave',
            'role' => 'admin',
        ]);

        $this->assertSame('admin', $result->role);
    }

    // ── Query hydration ─────────────────────────────────────────────────

    public function test_hydrate_query_from_constructor(): void
    {
        $result = $this->hydrator->hydrate(TestSimpleQuery::class, [
            'id' => 42,
        ]);

        $this->assertInstanceOf(TestSimpleQuery::class, $result);
        $this->assertSame(42, $result->id);
    }

    // ── Generic class hydration ─────────────────────────────────────────

    public function test_hydrate_generic_class_associative(): void
    {
        $result = $this->hydrator->hydrate(GenericDto::class, [
            'label' => 'Widget',
            'price' => 9.99,
        ]);

        $this->assertInstanceOf(GenericDto::class, $result);
        $this->assertSame('Widget', $result->label);
        $this->assertSame(9.99, $result->price);
    }

    public function test_hydrate_generic_class_positional(): void
    {
        $result = $this->hydrator->hydrate(GenericDto::class, [ // @phpstan-ignore argument.type
            0 => 'Gadget',
            1 => 19.99,
        ]);

        $this->assertInstanceOf(GenericDto::class, $result);
        $this->assertSame('Gadget', $result->label);
        $this->assertSame(19.99, $result->price);
    }

    public function test_hydrate_generic_class_defaults_used_for_missing_positional(): void
    {
        $result = $this->hydrator->hydrate(GenericDto::class, [ // @phpstan-ignore argument.type
            0 => 'Item',
        ]);

        $this->assertInstanceOf(GenericDto::class, $result);
        $this->assertSame('Item', $result->label);
        $this->assertSame(0.0, $result->price);
    }

    public function test_hydrate_no_constructor_class(): void
    {
        $result = $this->hydrator->hydrate(NoConstructorDto::class, []);

        $this->assertInstanceOf(NoConstructorDto::class, $result);
    }

    public function test_hydrate_value_object_preserves_array_constructor_arguments(): void
    {
        /** @var TestArrayValueObject $result */
        $result = $this->hydrator->hydrate(TestArrayValueObject::class, [
            'displayName' => 'Westmount, Montreal',
            'area' => ['Canada', 'Quebec', 'Montreal', 'Westmount'],
        ]);

        $this->assertSame(
            ['Canada', 'Quebec', 'Montreal', 'Westmount'],
            $result->area,
        );
    }

    public function test_hydrate_command_with_value_object_preserves_array_constructor_arguments(): void
    {
        /** @var TestCommandWithArrayValueObject $result */
        $result = $this->hydrator->hydrate(TestCommandWithArrayValueObject::class, [
            'location' => [
                'displayName' => 'Westmount, Montreal',
                'area' => ['Canada', 'Quebec', 'Montreal', 'Westmount'],
            ],
        ]);

        $this->assertSame(
            ['Canada', 'Quebec', 'Montreal', 'Westmount'],
            $result->location->area,
        );
    }

    public function test_hydrate_nested_value_object_preserves_array_constructor_arguments(): void
    {
        /** @var TestNestedArrayValueObject $result */
        $result = $this->hydrator->hydrate(TestNestedArrayValueObject::class, [
            'location' => [
                'displayName' => 'Westmount, Montreal',
                'area' => ['Canada', 'Quebec', 'Montreal', 'Westmount'],
            ],
        ]);

        $this->assertSame(
            ['Canada', 'Quebec', 'Montreal', 'Westmount'],
            $result->location->area,
        );
    }

    // ── Security: type boundary tests ───────────────────────────────────

    public function test_hydrate_rejects_non_string_non_object_target(): void
    {
        // The method signature requires string|object, so PHP itself
        // enforces this. We test with an empty class-string.
        $this->expectException(InvalidArgumentException::class);

        $this->hydrator->hydrate('', []); // @phpstan-ignore argument.type
    }

    public function test_hydrate_command_with_empty_data_uses_defaults_or_null(): void
    {
        $result = $this->hydrator->hydrate(TestOptionalCommand::class, [
            'name' => 'Eve',
        ]);

        $this->assertSame('user', $result->role);
    }
}
