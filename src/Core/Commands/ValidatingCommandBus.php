<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Commands;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Zolta\Cqrs\Commands\Contracts\CommandBusInterface;
use Zolta\Cqrs\Commands\Contracts\CommandInterface;
use Zolta\Cqrs\Commands\Contracts\CommandResultInterface;
use Zolta\Cqrs\Utils\ArgumentResolver;

/**
 * Decorator CommandBus that runs validators before dispatching commands.
 */
class ValidatingCommandBus implements CommandBusInterface
{
    /** @var array<class-string, class-string> */
    private array $validatorMap = [];

    private readonly ArgumentResolver $argumentResolver;

    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly ContainerInterface $container,
        private readonly ?ContainerInterface $validatorLocator = null,
    ) {
        $this->argumentResolver = new ArgumentResolver($validatorLocator ?? $container);
    }

    public function dispatch(CommandInterface $command, ...$args): mixed
    {
        $commandClass = $command::class;

        if (isset($this->validatorMap[$commandClass])) {
            $validatorClass = $this->validatorMap[$commandClass];
            $locator = $this->validatorLocator ?? $this->container;

            $validator = $locator->has($validatorClass)
                ? $locator->get($validatorClass)
                : $this->container->get($validatorClass);

            // Determine correct entrypoint (handle, __invoke, or attribute)
            $method = $this->argumentResolver->selectMethod($validator, $commandClass, 'validator');

            // Resolve only demanded args (combine dispatcher args + container)
            $resolvedArgs = $this->argumentResolver->resolveMethodArguments($validator, $method, $command, $args);

            $validationResult = (new \ReflectionMethod($validator, $method))
                ->invokeArgs($validator, $resolvedArgs);

            if ($validationResult instanceof CommandResultInterface && $validationResult->isFailure()) {
                return $validationResult;
            }
        }

        // Continue to next bus
        return $this->commandBus->dispatch($command, ...$args);
    }

    public function registerValidator(string $command, string $validator): void
    {
        if (! class_exists($command)) {
            throw new InvalidArgumentException("Command class '{$command}' does not exist.");
        }

        if (! class_exists($validator)) {
            throw new InvalidArgumentException("Validator class '{$validator}' does not exist.");
        }

        $this->validatorMap[$command] = $validator;
    }

    public function register(string $command, object|string $handler): void
    {
        $this->commandBus->register($command, $handler);
    }
}
