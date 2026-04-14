<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Repositories\Filters;

use Zolta\Framework\FrameworkRegistry;

/**
 * Framework-agnostic facade for date range filtering.
 *
 * Resolves to the framework-specific implementation via FrameworkRegistry bindings.
 */
abstract class DateRangeFilterFallback implements FilterInterface {}

$implementation = FrameworkRegistry::resolveBinding(DateRangeFilter::class);
$runtimeClass = $implementation !== null && class_exists($implementation)
    ? $implementation
    : DateRangeFilterFallback::class;

if (! class_exists(__NAMESPACE__.'\\FrameworkDateRangeFilter', false)) {
    class_alias($runtimeClass, __NAMESPACE__.'\\FrameworkDateRangeFilter');
}

// Static-analysis helper
// @phpstan-ignore-next-line
if (false) {
    class FrameworkDateRangeFilter implements FilterInterface {}
}

if (! class_exists(__NAMESPACE__.'\\DateRangeFilter', false)) {
    class DateRangeFilter extends FrameworkDateRangeFilter {}
}
