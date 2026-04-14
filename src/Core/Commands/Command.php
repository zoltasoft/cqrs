<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Commands;

use Zolta\Cqrs\Commands\Contracts\CommandInterface;
use Zolta\Support\Traits\Normalizable;

abstract class Command implements CommandInterface
{
    use Normalizable;
}
