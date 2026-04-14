<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Services;

/**
 * Marker that forces the CQRS proxy to resolve the wrapped path against captured data.
 */
final readonly class MapPlaceholder
{
    public function __construct(private string $path) {}

    public function value(): string
    {
        return $this->path;
    }
}
