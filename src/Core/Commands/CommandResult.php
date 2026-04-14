<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Commands;

final class CommandResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        private array $data = []
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }
}
