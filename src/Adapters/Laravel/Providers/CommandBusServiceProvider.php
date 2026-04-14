<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Zolta\Cqrs\Commands\Contracts\CommandBusInterface;
use Zolta\Cqrs\Factories\CommandBusFactory;
use Zolta\Framework\FrameworkRegistry;

class CommandBusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ----------------------------
        // CommandBus binding
        // ----------------------------
        $this->app->bind(CommandBusInterface::class, function (Container $container): CommandBusInterface {
            $binding = FrameworkRegistry::resolveBinding(CommandBusInterface::class);
            if (is_string($binding) && is_a($binding, CommandBusInterface::class, true)) {
                /** @var CommandBusInterface $bus */
                $bus = $container->make($binding);

                return $bus;
            }

            $commandMap = $container->get('command.map');

            return CommandBusFactory::create($container, $commandMap);
        });
    }

    public function boot(): void
    {
        // CommandHandler::setContainer($this->app);
    }
}
