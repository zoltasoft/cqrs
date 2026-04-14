<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Services;

use JsonSerializable;
use Zolta\Domain\Interfaces\Serializer;

class ToArraySerializer implements Serializer
{
    public function serialize(mixed $data): array
    {
        if (is_array($data)) {
            return $data;
        }

        if ($data instanceof JsonSerializable) {
            $json = $data->jsonSerialize();

            if (is_array($json)) {
                return $json;
            }
        }

        if (is_object($data) && method_exists($data, 'toArray')) {
            $converted = $data->toArray();

            return is_array($converted) ? $converted : (array) $converted;
        }

        return (array) $data;
    }
}
