<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Repositories\Cache;

use JsonException;

final readonly class HashedCacheKeyGenerator implements CacheKeyGenerator
{
    public function __construct(
        private string $prefix = 'zolta'
    ) {}

    public function generate(string $namespace, array $parameters = []): string
    {
        ksort($parameters);

        array_walk_recursive($parameters, static function (&$item): void {
            if (is_bool($item)) {
                $item = $item ? '1' : '0';

                return;
            }

            if (is_object($item)) {
                if (method_exists($item, '__toString')) {
                    $item = (string) $item;

                    return;
                }

                $item = spl_object_hash($item);
            }
        });

        try {
            $payload = json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            $payload = '';
        }

        return sprintf('%s:%s:%s', $this->prefix, $namespace, md5($payload));
    }

    public function prefix(): string
    {
        return $this->prefix;
    }
}
