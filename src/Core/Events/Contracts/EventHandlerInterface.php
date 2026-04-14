<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Events\Contracts;

use Zolta\Domain\Events\Contracts\EventInterface;

interface EventHandlerInterface
{
    /**
     * Handle the given domain event.
     */
    public function handleEvent(EventInterface $event): void;
}
