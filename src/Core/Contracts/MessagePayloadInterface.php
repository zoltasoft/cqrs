<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Contracts;

/**
 * Represents a structured payload returned by a CQRS command or query.
 */
interface MessagePayloadInterface
{
    /**
     * Convert the payload into an associative array representation.
     *
     * @return array<mixed>
     */
    public function toArray(): array;
}
