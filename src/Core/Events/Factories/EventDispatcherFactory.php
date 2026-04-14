<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Events\Factories;

use Zolta\Cqrs\Events\Contracts\EventDispatcherInterface;
use Zolta\Cqrs\Events\EventDispatcher;

class EventDispatcherFactory
{
    /**
     * @param  array<int, EventDispatcherInterface>  $dispatchers
     */
    public static function create(array $dispatchers): EventDispatcherInterface
    {
        return count($dispatchers) === 1 ? $dispatchers[0] : new EventDispatcher($dispatchers);
    }

    // /**
    //  * @param  array<string, array<int, class-string>>  $eventMap
    //  *
    //  * @deprecated 2.x Move to Zolta\Laravel\Factories\EventDispatcherFactory::create()
    //  */
    // public static function createForLaravel(
    //     \Illuminate\Contracts\Foundation\Application $application,
    //     array $eventMap,
    //     ?\Psr\Log\LoggerInterface $logger = null
    // ): EventDispatcherInterface {
    //     return \Zolta\Laravel\Factories\EventDispatcherFactory::create($application, $eventMap, $logger);
    // }
}
