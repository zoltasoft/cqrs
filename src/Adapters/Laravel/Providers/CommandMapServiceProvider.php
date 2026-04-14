<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Providers;

use ReflectionClass;
use Zolta\Cqrs\Attributes\HandlesCommand;
use Zolta\Cqrs\Attributes\ValidatesCommand;

class CommandMapServiceProvider extends BaseMapServiceProvider
{
    protected function getConfigEntriesKey(): string
    {
        return 'commands';
    }

    protected function getMapType(): string
    {
        return 'command';
    }

    /**
     * @param  ReflectionClass<object>  $reflectionClass
     * @param  array<string, array<int|string, string>>  $map
     */
    protected function mapClass(ReflectionClass $reflectionClass, array &$map): void
    {
        foreach ($reflectionClass->getAttributes(HandlesCommand::class) as $attribute) {
            $inst = $attribute->newInstance();
            $cmd = $inst->commandClass;
            $map[$cmd] ??= [];
            $map[$cmd]['handler'] = $reflectionClass->getName();
        }

        foreach ($reflectionClass->getAttributes(ValidatesCommand::class) as $attribute) {
            $inst = $attribute->newInstance();
            $cmd = $inst->commandClass;
            $map[$cmd] ??= [];
            $map[$cmd]['validator'] = $reflectionClass->getName();
        }
    }
}
