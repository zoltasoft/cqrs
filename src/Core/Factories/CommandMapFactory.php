<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Factories;

use ReflectionClass;
use Zolta\Cqrs\Attributes\HandlesCommand;
use Zolta\Cqrs\Attributes\ValidatesCommand;

class CommandMapFactory
{
    /**
     * Scan classes and build a command map.
     *
     * @param  array<int, string>  $files  PHP files to scan
     * @param  callable  $classLoader  Function to resolve FQCN to ReflectionClass
     * @param  callable|null  $singletonRegistrar  Optional callback to register singletons
     * @return array<class-string, array{handler?: string|object, validator?: string|object}>
     */
    public static function create(array $files, callable $classLoader, ?callable $singletonRegistrar = null): array
    {
        $map = [];

        foreach ($files as $file) {
            $fqcn = $classLoader($file);
            if (! is_string($fqcn) || $fqcn === '' || ! class_exists($fqcn, true)) {
                continue;
            }
            $ref = new ReflectionClass($fqcn);

            // HandlesCommand attribute
            foreach ($ref->getAttributes(HandlesCommand::class) as $attr) {
                $inst = $attr->newInstance();
                $cmd = $inst->commandClass;
                $map[$cmd] ??= [];
                $map[$cmd]['handler'] = $fqcn;
                if ($singletonRegistrar) {
                    $singletonRegistrar($fqcn);
                }
            }

            // ValidatesCommand attribute
            foreach ($ref->getAttributes(ValidatesCommand::class) as $attr) {
                $inst = $attr->newInstance();
                $cmd = $inst->commandClass;
                $map[$cmd] ??= [];
                $map[$cmd]['validator'] = $fqcn;
                if ($singletonRegistrar) {
                    $singletonRegistrar($fqcn);
                }
            }
        }

        return $map;
    }
}
