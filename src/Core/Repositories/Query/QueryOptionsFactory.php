<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Repositories\Query;

use Zolta\Cqrs\Repositories\Query\Exceptions\InvalidQueryOptionException;

/**
 * Normalizes input payload into a domain AbstractQueryOptions instance.
 *
 * NOTES:
 * - This factory purposely **does not drop** filter keys by default.
 *   Repositories are the authoritative place to whitelist allowed filters/sorts/relations.
 * - Strict mode rejects unsupported filters and sorts instead of silently dropping them.
 */
final class QueryOptionsFactory
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function make(array $payload = []): QueryOptions
    {
        // Default normalization
        $payload = $this->normalizePayload($payload);

        // Strict mode fails closed. Unknown public query options are never silently ignored.
        if (! empty($payload['strict'])) {
            $allowedFilters = $payload['allowed_filters'] ?? [];
            $allowedSorts = $payload['allowed_sorts'] ?? [];

            if (is_array($payload['filters'])) {
                $unknownFilters = array_values(array_filter(
                    array_keys($payload['filters']),
                    fn (string $key): bool => ! $this->isAllowedFilterKey($key, $allowedFilters),
                ));
                if ($unknownFilters !== []) {
                    throw new InvalidQueryOptionException('Unsupported filters: '.implode(', ', $unknownFilters));
                }
            }

            if (! empty($payload['sort'])) {
                $unknownSorts = array_values(array_filter((array) $payload['sort'], function ($s) use ($allowedSorts): bool {
                    $f = ltrim((string) $s, '-+');

                    return ! in_array($f, $allowedSorts, true);
                }));
                if ($unknownSorts !== []) {
                    throw new InvalidQueryOptionException('Unsupported sorts: '.implode(', ', $unknownSorts));
                }
            }
        }

        return new QueryOptions($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        // filters
        if (! array_key_exists('filters', $payload) || $payload['filters'] === null) {
            $payload['filters'] = [];
        }

        // ensure filters is array (if passed as JSON string, decode)
        if (is_string($payload['filters'])) {
            // try to decode JSON, fallback to empty
            $decoded = json_decode($payload['filters'], true);
            $payload['filters'] = is_array($decoded) ? $decoded : [];
        }

        // include -> array
        if (empty($payload['include'])) {
            $payload['include'] = [];
        } elseif (is_string($payload['include'])) {
            $payload['include'] = array_filter(array_map(trim(...), explode(',', $payload['include'])));
        } elseif (! is_array($payload['include'])) {
            $payload['include'] = [];
        }

        // sort -> array (keep direction marker -/+) or empty array
        if (empty($payload['sort'])) {
            $payload['sort'] = [];
        } elseif (is_string($payload['sort'])) {
            $payload['sort'] = array_filter(array_map(trim(...), explode(',', $payload['sort'])));
        } elseif (! is_array($payload['sort'])) {
            $payload['sort'] = [];
        }

        // limit/page -> ints or null
        $payload['limit'] = isset($payload['limit']) && is_numeric($payload['limit']) ? (int) $payload['limit'] : ($payload['limit'] ?? null);
        $payload['page'] = isset($payload['page']) && is_numeric($payload['page']) ? (int) $payload['page'] : ($payload['page'] ?? null);

        // context -> array
        if (empty($payload['context'])) {
            $payload['context'] = [];
        } elseif (! is_array($payload['context'])) {
            $payload['context'] = (array) $payload['context'];
        }

        return $payload;
    }

    /**
     * @param  array<int, string>  $allowedFilters
     */
    private function isAllowedFilterKey(string $key, array $allowedFilters): bool
    {
        if (preg_match('/^(.+)\[(.+)\]$/', $key, $m)) {
            $key = $m[1];
        }

        return in_array($key, $allowedFilters, true);
    }
}
