<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Repositories\Query;

/**
 * Normalizes input payload into a domain AbstractQueryOptions instance.
 *
 * NOTES:
 * - This factory purposely **does not drop** filter keys by default.
 *   Repositories are the authoritative place to whitelist allowed filters/sorts/relations.
 * - If you want pre-sanitization, pass ['strict' => true, 'allowed_filters' => [...], 'allowed_sorts' => [...]]
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

        // Strict mode (optional): drop unknown filters/sorts if caller provided allowed lists
        if (! empty($payload['strict'])) {
            $allowedFilters = $payload['allowed_filters'] ?? [];
            $allowedSorts = $payload['allowed_sorts'] ?? [];

            if (! empty($allowedFilters) && is_array($payload['filters'])) {
                $incomingFilters = array_keys((array) $payload['filters']);
                $payload['filters'] = array_filter(
                    $payload['filters'],
                    fn (string $key): bool => $this->isAllowedFilterKey($key, $allowedFilters),
                    ARRAY_FILTER_USE_KEY
                );
            }

            if (! empty($allowedSorts) && ! empty($payload['sort'])) {
                $payload['sort'] = array_filter((array) $payload['sort'], function ($s) use ($allowedSorts): bool {
                    $f = ltrim((string) $s, '-+');

                    return in_array($f, $allowedSorts, true);
                });
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
