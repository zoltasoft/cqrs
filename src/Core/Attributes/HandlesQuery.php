<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class HandlesQuery
{
    /**
     * @param  class-string  $queryClass
     */
    public function __construct(public string $queryClass, public ?string $methodName = null) {}
}
