<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Repositories\Query\Exceptions;

use InvalidArgumentException;

final class InvalidRepositoryConstraintException extends InvalidArgumentException {}
