<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Repositories\Cache;

interface CacheKeyGenerator
{
    /**
     * @param  array<string,mixed>  $parameters
     */
    public function generate(string $namespace, array $parameters = []): string;

    /**
     * Return the namespace prefix used for cache keys.
     */
    public function prefix(): string;
}
