<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Commands\Contracts;

use Zolta\Domain\Events\Contracts\EventInterface;

/**
 * CommandResultInterface
 *
 * Represents the outcome of a command execution.
 * Provides access to:
 *  - payload/value returned by the command (getValue/getOrFail/all)
 *  - domain events produced by the command (getEvents/releaseEvents)
 *
 * Implementations MUST be immutable from a consumer perspective.
 */
interface CommandResultInterface
{
    /**
     * Return current events (do NOT clear them).
     *
     * @return EventInterface[]
     */
    public function getEvents(): array;

    /**
     * Return events and clear them from the result (ownership transfer).
     *
     * @return EventInterface[]
     */
    public function releaseEvents(): array;

    public function isSuccess(): bool;

    public function isFailure(): bool;

    /**
     * Get the underlying error (only for failure).
     *
     * @throws \LogicException if called on success
     */
    public function getError(): \Throwable;

    /**
     * Get the value/payload of a successful result.
     *
     * @throws \LogicException if called on failure
     */
    public function getValue(): mixed;

    /**
     * Return the value or throw a mapped exception produced by $onFailure.
     * When provided, $onSuccess is invoked with the successful payload (before returning it).
     *
     * @param  callable|null  $onFailure  function(\Throwable $error, mixed $value = null): ?\Throwable
     * @param  callable|null  $onSuccess  function(mixed $value, ?\Throwable $error = null): void
     *
     * @throws \Throwable
     */
    public function getOrFail(?callable $onFailure = null, ?callable $onSuccess = null): mixed;

    /**
     * Convenience helper (keeps compatibility with legacy CommandResult::all()).
     */
    public function get(): mixed;

    /**
     * Convert the value to an array (only if the value is an array).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    // --- Static factory helpers ---

    /**
     * Create a successful result with optional value and events.
     *
     * @param  EventInterface[]  $events
     */
    public static function success(
        mixed $value = null,
        array $events = [],
    ): static;

    /**
     * Create a successful result that only carries events.
     *
     * @param  EventInterface[]  $events
     */
    public static function successWithEvents(
        array $events = [],
    ): static;

    /**
     * Create a failure result carrying an error and optional events.
     *
     * @param  EventInterface[]  $events
     */
    public static function failure(
        \Throwable $throwable,
        array $events = [],
    ): static;
}
