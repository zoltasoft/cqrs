<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Queries;

use Psr\Container\ContainerInterface;
use ReflectionMethod;
use RuntimeException;
use Zolta\Cqrs\Queries\Contracts\QueryBusInterface;
use Zolta\Cqrs\Queries\Contracts\QueryInterface;
use Zolta\Cqrs\Utils\ArgumentResolver;

/**
 * Query bus that resolves handler entrypoint and arguments dynamically,
 * reusing ArgumentResolver for consistent behaviour and caching.
 */
class InMemoryQueryBus implements QueryBusInterface
{
    /** @var array<class-string, class-string> */
    private array $handlers = [];

    private readonly ArgumentResolver $argumentResolver;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?ContainerInterface $handlerLocator = null
    ) {
        // Use the root container for argument resolution so dependencies beyond handlers are available.
        $this->argumentResolver = new ArgumentResolver($container);
    }

    /**
     * Register a query -> handler mapping.
     */
    public function register(string $queryClass, string $handlerClass): void
    {
        $this->handlers[$queryClass] = $handlerClass;
    }

    /**
     * Ask for a query result.
     *
     * @param  mixed  ...$args
     */
    public function ask(QueryInterface $query, ...$args): mixed
    {
        $queryClass = $query::class;

        if (! isset($this->handlers[$queryClass])) {
            throw new RuntimeException("No handler registered for {$queryClass}");
        }

        $handlerRef = $this->handlers[$queryClass];

        // Resolve handler strictly via locator/container (no silent fallback)
        $handler = is_string($handlerRef)
            ? $this->resolveHandler($handlerRef)
            : $handlerRef;

        // determine entrypoint (uses attribute HandlesQuery, __invoke or 'handle' default)
        $methodName = $this->argumentResolver->selectMethod($handler, $queryClass, 'query');

        // resolve only demanded args for that method
        $resolvedArgs = $this->argumentResolver->resolveMethodArguments($handler, $methodName, $query, $args);

        // invoke and return the result
        if ($methodName === '__invoke') {
            // callable object
            return $handler(...$resolvedArgs);
        }

        // method invocation
        $reflectionMethod = new ReflectionMethod($handler, $methodName);

        return $reflectionMethod->invokeArgs($handler, $resolvedArgs);
    }

    private function resolveHandler(string $handlerClass): object
    {
        if (! class_exists($handlerClass)) {
            throw new RuntimeException("Handler class {$handlerClass} does not exist.");
        }

        $locator = $this->handlerLocator ?? $this->container;

        if ($locator->has($handlerClass)) {
            return $locator->get($handlerClass);
        }

        // Laravel's container can auto-resolve unbound classes via make()
        if (method_exists($this->container, 'make')) {
            try {
                /** @var object $instance */
                $instance = $this->container->make($handlerClass);

                return $instance;
            } catch (\Throwable $e) {
                throw new RuntimeException("Query handler {$handlerClass} could not be resolved. Bind it or use method injection for dependencies.", $e->getCode(), previous: $e);
            }
        }

        throw new RuntimeException(
            "Query handler {$handlerClass} is not bound in the container. Bind it or use method injection for dependencies."
        );
    }
}
