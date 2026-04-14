<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Commands;

use Psr\Container\ContainerInterface;
use ReflectionMethod;
use RuntimeException;
use Zolta\Cqrs\Commands\Contracts\CommandBusInterface;
use Zolta\Cqrs\Commands\Contracts\CommandInterface;
use Zolta\Cqrs\Services\Result;
use Zolta\Cqrs\Utils\ArgumentResolver;

class SynchronousCommandBus implements CommandBusInterface
{
    private readonly ArgumentResolver $argumentResolver;

    /**
     * @param  array<class-string<CommandInterface>, object|string>  $handlerMap
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private array $handlerMap = [],
        private readonly ?ContainerInterface $handlerLocator = null,
    ) {
        $locator = $handlerLocator ?? $container;
        $this->argumentResolver = new ArgumentResolver($locator);
    }

    public function dispatch(CommandInterface $command, ...$args): mixed
    {
        $commandClass = $command::class;

        if (! isset($this->handlerMap[$commandClass])) {
            throw new RuntimeException("No handler registered for {$commandClass}");
        }

        $handlerRef = $this->handlerMap[$commandClass];
        $handler = is_string($handlerRef)
            ? $this->resolveHandler($handlerRef)
            : $handlerRef;

        // Determine which method to call
        $methodName = $this->argumentResolver->selectMethod($handler, $commandClass, 'command');

        // Resolve only demanded args for that method
        $resolvedArgs = $this->argumentResolver->resolveMethodArguments($handler, $methodName, $command, $args);

        // Call method dynamically
        if ($methodName === '__invoke') {
            $result = $handler(...$resolvedArgs);
        } else {
            $reflectionMethod = new ReflectionMethod($handler, $methodName);
            $result = $reflectionMethod->invokeArgs($handler, $resolvedArgs);
        }

        return $result instanceof Result ? $result : Result::success($result);
    }

    public function register(string $command, object|string $handler): void
    {
        $this->handlerMap[$command] = $handler;
    }

    private function resolveHandler(string $handlerClass): object
    {
        $locator = $this->handlerLocator ?? $this->container;

        if ($locator->has($handlerClass)) {
            return $locator->get($handlerClass);
        }

        if (method_exists($this->container, 'make')) {
            try {
                /** @var object $instance */
                $instance = $this->container->make($handlerClass);

                return $instance;
            } catch (\Throwable $e) {
                throw new RuntimeException("Command handler {$handlerClass} could not be resolved. Bind it or use method injection for dependencies.", $e->getCode(), $e);
            }
        }

        throw new RuntimeException("Command handler {$handlerClass} is not bound in the container. Bind it or use method injection for dependencies.");
    }
}
