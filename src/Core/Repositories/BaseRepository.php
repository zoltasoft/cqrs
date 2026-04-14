<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Repositories;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Zolta\Cqrs\Laravel\Eloquent\EloquentBaseRepository;
use Zolta\Cqrs\Repositories\Query\Services\AbstractRepository;
use Zolta\Framework\FrameworkRegistry;

/**
 * Bridge that aliases to the framework-specific repository base resolved by FrameworkRegistry.
 */
abstract class RepositoryFallback extends AbstractRepository
{
    /**
     * @param  array<int|string,mixed>  $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        throw new LogicException("Repository base not bound for method '{$name}'.");
    }
}

$implementation = FrameworkRegistry::resolveBinding(BaseRepository::class);
$runtimeClass = $implementation !== null && class_exists($implementation)
    ? $implementation
    : RepositoryFallback::class;

$implClass = __NAMESPACE__.'\\FrameworkBaseRepositoryImplementation';
if (! class_exists($implClass, false)) {
    class_alias($runtimeClass, $implClass);
}

if (! class_exists(__NAMESPACE__.'\\FrameworkBoundBaseRepository', false)) {
    abstract class FrameworkBoundBaseRepository extends FrameworkBaseRepositoryImplementation {}
}

// Static-analysis helper — tells IDEs about the runtime chain resolved via FrameworkRegistry.
if (false) { // @phpstan-ignore-line
    /** @extends EloquentBaseRepository<Model> */
    abstract class FrameworkBaseRepositoryImplementation extends EloquentBaseRepository {}
    abstract class FrameworkBoundBaseRepository extends FrameworkBaseRepositoryImplementation {}
}

abstract class BaseRepository extends FrameworkBoundBaseRepository {}
