<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Services;

use InvalidArgumentException;
use ReflectionClass;
use Zolta\Cqrs\Commands\Contracts\CommandBusInterface;
use Zolta\Cqrs\Commands\Contracts\CommandInterface;
use Zolta\Cqrs\Contracts\CqrsServiceInterface;
use Zolta\Cqrs\Hydration\MessageHydratorInterface;
use Zolta\Cqrs\Queries\Contracts\QueryBusInterface;
use Zolta\Cqrs\Queries\Contracts\QueryInterface;
use Zolta\Domain\Cache\ReflectionCache;

final readonly class Cqrs implements CqrsServiceInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private MessageHydratorInterface $messageHydrator
    ) {}

    /**
     * Dispatch a command or command class with optional data.
     *
     * Usage:
     *   dispatch($commandInstance, ...$busArgs)
     *   dispatch(CommandClass::class, ['foo'=>'bar'], ...$busArgs)
     */
    public function dispatch(CommandInterface|string $commandOrClass, mixed ...$args): mixed
    {
        if (is_string($commandOrClass)) {
            [$data, $remainingArgs] = $this->extractDataAndArgs($args);
            $commandOrClass = $this->make($commandOrClass, $data);

            return $this->commandBus->dispatch($commandOrClass, ...$remainingArgs);
        }

        return $this->commandBus->dispatch($commandOrClass, ...$args);
    }

    /**
     * Execute a query or query class with optional data.
     *
     * Usage:
     *   ask($queryInstance, ...$busArgs)
     *   ask(QueryClass::class, ['foo'=>'bar'], ...$busArgs)
     */
    public function ask(QueryInterface|string $queryOrClass, mixed ...$args): mixed
    {
        if (is_string($queryOrClass)) {
            [$data, $remainingArgs] = $this->extractDataAndArgs($args);
            $queryOrClass = $this->make($queryOrClass, $data);

            return $this->queryBus->ask($queryOrClass, ...$remainingArgs);
        }

        return $this->queryBus->ask($queryOrClass, ...$args);
    }

    public function make(string|object $class, array $data = []): mixed
    {
        return $this->messageHydrator->hydrate($class, $data);
    }

    /**
     * Resolve and execute a command or query (class-string accepted).
     *
     * @param  mixed  ...$args  If $message is class-string: first arg optionally is data array, rest forwarded to bus
     */
    public function run(CommandInterface|QueryInterface|string $message, mixed ...$args): mixed
    {
        $messageClass = is_string($message) ? $message : $message::class;
        $operationType = is_string($message) ? $this->detectMessageType($message) : ($message instanceof CommandInterface ? 'command' : 'query');

        try {
            if (is_string($message)) {
                [$data, $remainingArgs] = $this->extractDataAndArgs($args);

                $result = $operationType === 'command'
                    ? $this->dispatch($message, $data, ...$remainingArgs)
                    : $this->ask($message, $data, ...$remainingArgs);
            } else {
                $result = $operationType === 'command'
                    ? $this->dispatch($message, ...$args)
                    : $this->ask($message, ...$args);
            }

            return $result;
        } catch (\Throwable $ex) {
            throw $ex;
        }
    }

    /**
     * Extract data (first arg if associative array) and remaining args to forward to the bus.
     *
     * @param  array<mixed>  $args
     * @return array{array<mixed>, array<mixed>}
     */
    private function extractDataAndArgs(array $args): array
    {
        $data = [];
        if (isset($args[0]) && is_array($args[0])) {
            $data = $args[0];
            array_shift($args);
        }

        return [$data, $args];
    }

    /**
     * Detect whether a class is a Command or Query.
     *
     * @param  class-string  $class
     * @return 'command'|'query'
     */
    private function detectMessageType(string $class): string
    {
        if (! class_exists($class)) {
            throw new InvalidArgumentException(sprintf('Class "%s" does not exist.', $class));
        }

        ReflectionCache::getClassAttributes($class);

        static $reflectionPool = [];
        $reflection = $reflectionPool[$class] ??= new ReflectionClass($class);

        if ($reflection->implementsInterface(CommandInterface::class)) {
            return 'command';
        }

        if ($reflection->implementsInterface(QueryInterface::class)) {
            return 'query';
        }

        throw new InvalidArgumentException(sprintf('The class "%s" does not implement CommandInterface or QueryInterface.', $class));
    }
}
