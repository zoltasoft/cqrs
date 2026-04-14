<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Repositories\Filters;

use Zolta\Framework\FrameworkRegistry;

/**
 * Framework-agnostic facade for search filtering.
 *
 * Resolves to the framework-specific implementation via FrameworkRegistry bindings.
 */
abstract class SearchFilterFallback implements FilterInterface {}

$implementation = FrameworkRegistry::resolveBinding(SearchFilter::class);
$runtimeClass = $implementation !== null && class_exists($implementation)
    ? $implementation
    : SearchFilterFallback::class;

if (! class_exists(__NAMESPACE__.'\\FrameworkSearchFilter', false)) {
    class_alias($runtimeClass, __NAMESPACE__.'\\FrameworkSearchFilter');
}

// Static-analysis helper
// @phpstan-ignore-next-line
if (false) {
    class FrameworkSearchFilter implements FilterInterface {}
}

if (! class_exists(__NAMESPACE__.'\\SearchFilter', false)) {
    class SearchFilter extends FrameworkSearchFilter {}
}
