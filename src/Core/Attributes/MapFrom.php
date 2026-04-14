<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class MapFrom
{
    public function __construct(public string $sourceDTO) {}
}
