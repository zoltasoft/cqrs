<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class HandlesCommand
{
    /**
     * @param  class-string  $commandClass
     */
    public function __construct(public string $commandClass, public ?string $methodName = null) {}
}
