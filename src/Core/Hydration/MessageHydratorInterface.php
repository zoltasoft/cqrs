<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Hydration;

interface MessageHydratorInterface
{
    /**
     * Hydrate any Command, Query, or ValueObject.
     *
     * @param  class-string|object  $target
     * @param  array<string,mixed>  $data
     */
    public function hydrate(string|object $target, array $data = []): object;
}
