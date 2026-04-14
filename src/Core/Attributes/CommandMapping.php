<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class CommandMapping
{
    public function __construct(
        public string $commandClass,
        public string $dtoClass
    ) {}
}
