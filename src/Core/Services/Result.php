<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Services;

use Closure;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use Zolta\Cqrs\Commands\Contracts\CommandResultInterface;
use Zolta\Cqrs\Contracts\MessagePayloadInterface;
use Zolta\Cqrs\Payload\ArrayMessagePayload;
use Zolta\Domain\Events\Contracts\EventInterface;

final class Result implements CommandResultInterface
{
    /** @var MessagePayloadInterface|array<string, mixed>|null */
    private readonly MessagePayloadInterface|array|null $value;

    private function __construct(
        private readonly bool $isSuccess,
        mixed $value = null,
        private readonly ?\Throwable $throwable = null,
        /** @var EventInterface[] */
        private array $events = [],
    ) {
        $this->value = $this->isSuccess ? $this->normalizeValue($value) : null;
    }

    public static function success(mixed $value = null, array $events = []): static
    {
        return new self(true, $value, null, $events);
    }

    public static function successWithEvents(array $events = []): static
    {
        return new self(true, new ArrayMessagePayload([]), null, $events);
    }

    public static function failure(\Throwable $throwable, array $events = []): static
    {
        return new self(false, null, $throwable, $events);
    }

    public function isSuccess(): bool
    {
        return $this->isSuccess;
    }

    public function isFailure(): bool
    {
        return ! $this->isSuccess;
    }

    public function getValue(): mixed
    {
        if ($this->isFailure()) {
            throw new \LogicException('Cannot get value from failure Result.');
        }

        return $this->value instanceof MessagePayloadInterface
            ? $this->value->toArray()
            : $this->value;
    }

    public function getOrFail(?callable $onFailure = null, ?callable $onSuccess = null): mixed
    {
        if ($this->isSuccess) {
            $payload = $this->toArray();

            if ($onSuccess !== null) {
                $this->invokeSuccessCallback($onSuccess, $payload);
            }

            return $payload;
        }

        if ($onFailure !== null) {
            $mapped = $this->invokeFailureCallback($onFailure, $this->throwable);
            if ($mapped instanceof \Throwable) {
                throw $mapped;
            }
            throw $this->throwable;
        }

        throw $this->throwable;
    }

    public function getEvents(): array
    {
        return $this->events;
    }

    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    public function getError(): \Throwable
    {
        if ($this->isSuccess) {
            throw new \LogicException('Cannot get error from success Result.');
        }

        return $this->throwable;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->value instanceof MessagePayloadInterface) {
            return $this->value->toArray();
        }

        if (is_array($this->value)) {
            return $this->value;
        }

        throw new \LogicException('Result value is not convertible to array via toArray().');
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function invokeSuccessCallback(callable $callback, array $value): void
    {
        $reflectionFunctionAbstract = $this->reflectCallable($callback);
        $paramCount = $reflectionFunctionAbstract->getNumberOfParameters();
        $arguments = [];

        if ($paramCount >= 1) {
            $arguments[] = $value;
        }

        if ($paramCount >= 2) {
            $arguments[] = null; // Placeholder error slot for symmetry
        }

        $callback(...$arguments);
    }

    private function invokeFailureCallback(callable $callback, \Throwable $throwable): mixed
    {
        $reflectionFunctionAbstract = $this->reflectCallable($callback);
        $paramCount = $reflectionFunctionAbstract->getNumberOfParameters();

        if ($paramCount === 0) {
            return $callback();
        }

        $arguments = [$throwable];

        if ($paramCount >= 2) {
            $arguments[] = null; // No payload available when failing
        }

        return $callback(...$arguments);
    }

    private function reflectCallable(callable $callback): ReflectionFunctionAbstract
    {
        if ($callback instanceof Closure) {
            return new ReflectionFunction($callback);
        }

        if (is_array($callback)) {
            return new ReflectionMethod($callback[0], $callback[1]);
        }

        if (is_string($callback) && str_contains($callback, '::')) {
            [$class, $method] = explode('::', $callback, 2);

            return new ReflectionMethod($class, $method);
        }

        return new ReflectionFunction(Closure::fromCallable($callback));
    }

    /**
     * @return MessagePayloadInterface|array<string, mixed>
     */
    private function normalizeValue(mixed $value): MessagePayloadInterface|array
    {
        if ($value === null) {
            return new ArrayMessagePayload([]);
        }

        if ($value instanceof MessagePayloadInterface || is_array($value)) {
            return $value;
        }

        throw new InvalidArgumentException('Result payloads must be arrays or implement MessagePayloadInterface.');
    }
}
