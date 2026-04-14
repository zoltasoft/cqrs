<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Payload;

use JsonSerializable;
use Zolta\Cqrs\Contracts\MessagePayloadInterface;

/**
 * Lightweight wrapper for associative-array payloads.
 */
final readonly class ArrayMessagePayload implements JsonSerializable, MessagePayloadInterface
{
    /**
     * @param  array<mixed>  $data
     */
    public function __construct(private array $data) {}

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): mixed
    {
        return $this->data;
    }
}
