<?php

declare(strict_types=1);

use Zolta\Cqrs\Services\MapPlaceholder;

if (! function_exists('map')) {
    /**
     * Create a placeholder so the CQRS proxy resolves the string against captured data.
     */
    function map(string $value): MapPlaceholder
    {
        return new MapPlaceholder($value);
    }
}
