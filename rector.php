<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\BooleanAnd\RemoveUselessIsObjectCheckRector;
use Rector\Config\RectorConfig;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;
use Rector\Privatization\Rector\ClassMethod\PrivatizeFinalClassMethodRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictNativeCallRector;
use Rector\TypeDeclaration\Rector\Property\TypedPropertyFromStrictConstructorRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;

return static function (RectorConfig $rectorConfig): void {

    // ----------------------------------------------
    // 1) Target directories
    // ----------------------------------------------
    $rectorConfig->paths([
        __DIR__.'/src',
    ]);

    // ----------------------------------------------
    // 2) Areas Rector must never touch
    // ----------------------------------------------
    $rectorConfig->skip([
        __DIR__.'/vendor',

        // VO system relies on manual constructors & factories
        __DIR__.'/src/Core/Domain/ValueObjects',

        // Domain events resolved by reflection
        __DIR__.'/src/Core/Domain/Events',

        // dynamic resolution logic
        __DIR__.'/src/Core/Application/Traits',

        // Tests must never be refactored
        __DIR__.'/tests',

        // Keep bridge hooks visible to traits
        PrivatizeFinalClassMethodRector::class => [
            __DIR__.'/src/Core/API/Requests/Bridge/BridgeRequest.php',
        ],

        RemoveUselessIsObjectCheckRector::class => [
            __DIR__.'/src/Core/Application/Hydration/DefaultMessageHydrator.php',
        ],

        // Keep setFactory callables as arrays for phpstan compatibility
        ArrayToFirstClassCallableRector::class => [
            __DIR__.'/src/Symfony/DependencyInjection/Registrar/BusRegistrar.php',
            __DIR__.'/src/Symfony/DependencyInjection/Registrar/CqrsMapRegistrar.php',
        ],
    ]);

    // ----------------------------------------------
    // 2b) Runtime configuration
    // ----------------------------------------------
    $rectorConfig->disableParallel();

    // ----------------------------------------------
    // 3) Base upgrade sets
    // ----------------------------------------------
    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_82,
        SetList::TYPE_DECLARATION,
        SetList::NAMING,
        SetList::PRIVATIZATION,
        SetList::CODE_QUALITY,      // safe, improves code readability
    ]);

    // ----------------------------------------------
    // 4) Extra strict rules — safe for Zolta Forge
    // ----------------------------------------------
    $rectorConfig->rules([
        // Adds return types only when 100% certain
        ReturnTypeFromStrictNativeCallRector::class,

        // Convert constructor assignments → typed properties
        TypedPropertyFromStrictConstructorRector::class,

        // Add strict_types=1
        DeclareStrictTypesRector::class,
    ]);
};
