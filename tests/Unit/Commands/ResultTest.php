<?php

declare(strict_types=1);

namespace Zolta\Tests\Unit\Commands;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Zolta\Cqrs\Payload\ArrayMessagePayload;
use Zolta\Cqrs\Services\Result;
use Zolta\Domain\Events\Contracts\EventInterface;

final class ResultTest extends TestCase
{
    // ── Factory methods ─────────────────────────────────────────────────

    public function test_success_with_array_payload(): void
    {
        $result = Result::success(['key' => 'value']);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
        $this->assertSame(['key' => 'value'], $result->getValue());
    }

    public function test_success_with_null_returns_empty_array(): void
    {
        $result = Result::success();

        $this->assertTrue($result->isSuccess());
        $this->assertSame([], $result->getValue());
    }

    public function test_success_with_message_payload(): void
    {
        $payload = new ArrayMessagePayload(['foo' => 'bar']);
        $result = Result::success($payload);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['foo' => 'bar'], $result->getValue());
    }

    public function test_success_rejects_non_array_non_payload(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Result::success('invalid string payload');
    }

    public function test_failure_creation(): void
    {
        $error = new \RuntimeException('Something broke');
        $result = Result::failure($error);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isSuccess());
        $this->assertSame($error, $result->getError());
    }

    public function test_success_with_events(): void
    {
        $event = $this->createMock(EventInterface::class);
        $result = Result::success(['data' => 1], [$event]);

        $this->assertCount(1, $result->getEvents());
    }

    public function test_success_with_events_only(): void
    {
        $event = $this->createMock(EventInterface::class);
        $result = Result::successWithEvents([$event]);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $result->getEvents());
        $this->assertSame([], $result->getValue());
    }

    // ── getValue guards ─────────────────────────────────────────────────

    public function test_get_value_on_failure_throws(): void
    {
        $result = Result::failure(new \RuntimeException('fail'));

        $this->expectException(LogicException::class);
        $result->getValue();
    }

    public function test_get_error_on_success_throws(): void
    {
        $result = Result::success(['ok' => true]);

        $this->expectException(LogicException::class);
        $result->getError();
    }

    // ── releaseEvents ───────────────────────────────────────────────────

    public function test_release_events_clears_internal_list(): void
    {
        $event = $this->createMock(EventInterface::class);
        $result = Result::success(['data' => 1], [$event]);

        $released = $result->releaseEvents();
        $this->assertCount(1, $released);

        $second = $result->releaseEvents();
        $this->assertCount(0, $second);
    }

    public function test_get_events_does_not_clear(): void
    {
        $event = $this->createMock(EventInterface::class);
        $result = Result::success(null, [$event]);

        $this->assertCount(1, $result->getEvents());
        $this->assertCount(1, $result->getEvents());
    }

    // ── toArray ─────────────────────────────────────────────────────────

    public function test_to_array_from_array_value(): void
    {
        $result = Result::success(['a' => 1, 'b' => 2]);

        $this->assertSame(['a' => 1, 'b' => 2], $result->toArray());
    }

    public function test_to_array_from_payload(): void
    {
        $result = Result::success(new ArrayMessagePayload(['x' => 'y']));

        $this->assertSame(['x' => 'y'], $result->toArray());
    }

    public function test_get_alias_for_to_array(): void
    {
        $result = Result::success(['id' => 42]);

        $this->assertSame(['id' => 42], $result->get());
    }

    // ── getOrFail ───────────────────────────────────────────────────────

    public function test_get_or_fail_returns_value_on_success(): void
    {
        $result = Result::success(['id' => 1]);

        $value = $result->getOrFail();
        $this->assertSame(['id' => 1], $value);
    }

    public function test_get_or_fail_throws_original_on_failure(): void
    {
        $error = new \RuntimeException('oops');
        $result = Result::failure($error);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('oops');
        $result->getOrFail();
    }

    public function test_get_or_fail_invokes_on_success_callback(): void
    {
        $result = Result::success(['id' => 1]);
        $captured = null;

        $result->getOrFail(null, function (array $payload) use (&$captured) {
            $captured = $payload;
        });

        $this->assertSame(['id' => 1], $captured);
    }

    public function test_get_or_fail_invokes_on_failure_callback_and_throws_mapped(): void
    {
        $original = new \RuntimeException('original');
        $result = Result::failure($original);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('mapped');

        $result->getOrFail(function (\Throwable $e) {
            return new LogicException('mapped', 0, $e);
        });
    }

    public function test_get_or_fail_on_failure_callback_returns_non_throwable_throws_original(): void
    {
        $original = new \RuntimeException('original');
        $result = Result::failure($original);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('original');

        $result->getOrFail(function (\Throwable $e) {
            return 'not a throwable';
        });
    }

    // ── Failure with events ─────────────────────────────────────────────

    public function test_failure_carries_events(): void
    {
        $event = $this->createMock(EventInterface::class);
        $result = Result::failure(new \RuntimeException('err'), [$event]);

        $this->assertCount(1, $result->getEvents());
        $this->assertTrue($result->isFailure());
    }
}
