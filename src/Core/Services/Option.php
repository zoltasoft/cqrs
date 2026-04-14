<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Services;

use Closure;
use LogicException;
use ReflectionFunction;
use Throwable;
use Zolta\Cqrs\Contracts\MessagePayloadInterface;
use Zolta\Cqrs\Payload\ArrayMessagePayload;

abstract class Option
{
    /**
     * @param  MessagePayloadInterface|array<string, mixed>  $values
     */
    public static function some(MessagePayloadInterface|array $values): Option
    {
        return new Some($values instanceof MessagePayloadInterface ? $values : new ArrayMessagePayload($values));
    }

    public static function none(): Option
    {
        return new None;
    }

    public static function error(Throwable $throwable): Option
    {
        return new ErrorOption($throwable);
    }

    abstract public function isSome(): bool;

    abstract public function isNone(): bool;

    /**
     * @return array<string, mixed>
     */
    abstract public function getOrNull(): array;

    protected function failureThrowable(): ?Throwable
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function getOrElse(array $default): array
    {
        return $this->isSome() ? $this->getOrNull() : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return $this->getOrNull();
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->getOrNull();
    }

    /**
     * Retrieve the wrapped values or throw.
     *
     * Behaviour:
     * - When the option is Some, the values are returned and $onSuccess (if provided) receives them.
     * - When the option is None/Error, the failure callback receives the originating Throwable (when available).
     *
     * @param  Closure|null  $exceptionFactory  function(\Throwable $error, ?array $value = null): ?\Throwable
     * @param  Closure|null  $onSuccess  function(array<string,mixed> $value, ?\Throwable $error = null): void
     * @return array<string, mixed>
     */
    public function getOrFail(?Closure $exceptionFactory = null, ?Closure $onSuccess = null): array
    {
        if ($this->isSome()) {
            $values = $this->getOrNull();

            if ($onSuccess instanceof Closure) {
                $this->invokeSuccessCallback($onSuccess, $values);
            }

            return $values;
        }

        $failure = $this->failureThrowable();
        $defaultException = $failure ?? new LogicException('Cannot retrieve value from None Option.');

        if ($exceptionFactory instanceof Closure) {
            $mappedException = $this->invokeFailureCallback($exceptionFactory, $defaultException);

            if ($mappedException instanceof Throwable) {
                throw $mappedException;
            }

            throw $defaultException;
        }

        throw $defaultException;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function invokeSuccessCallback(Closure $callback, array $values): void
    {
        $reflectionFunction = new ReflectionFunction($callback);
        $paramCount = $reflectionFunction->getNumberOfParameters();
        $arguments = [];

        if ($paramCount >= 1) {
            $arguments[] = $values;
        }

        if ($paramCount >= 2) {
            $arguments[] = null;
        }

        $callback(...$arguments);
    }

    private function invokeFailureCallback(Closure $callback, Throwable $throwable): mixed
    {
        $reflectionFunction = new ReflectionFunction($callback);
        $paramCount = $reflectionFunction->getNumberOfParameters();
        $arguments = [];

        if ($paramCount >= 1) {
            $arguments[] = $throwable;
        }

        if ($paramCount >= 2) {
            $arguments[] = null;
        }

        return $callback(...$arguments);
    }

    /**
     * Wrap a single value or multiple key/value pairs in an Option.
     *
     * @param  mixed|array<string, mixed>|MessagePayloadInterface  $value
     */
    public static function of(mixed $value, ?string $key = null): Option
    {
        if ($value === null) {
            return self::none();
        }

        if ($value instanceof MessagePayloadInterface) {
            return self::some($value);
        }

        if (is_array($value)) {
            return self::some($value);
        }

        $payload = [$key ?? 'value' => $value];

        return self::some($payload);
    }
}

final class Some extends Option
{
    public function __construct(
        private readonly MessagePayloadInterface $messagePayload
    ) {}

    public function isSome(): bool
    {
        return true;
    }

    public function isNone(): bool
    {
        return false;
    }

    public function fetch(string $key): mixed
    {
        $values = $this->messagePayload->toArray();

        return $values[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrNull(): array
    {
        return $this->messagePayload->toArray();
    }
}

final class None extends Option
{
    public function isSome(): bool
    {
        return false;
    }

    public function isNone(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrNull(): array
    {
        return [];
    }

    public function fetch(string $key): mixed
    {
        return null;
    }
}

final class ErrorOption extends Option
{
    public function __construct(private readonly Throwable $throwable) {}

    public function isSome(): bool
    {
        return false;
    }

    public function isNone(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrNull(): array
    {
        return [];
    }

    public function fetch(string $key): mixed
    {
        return null;
    }

    protected function failureThrowable(): Throwable
    {
        return $this->throwable;
    }
}
