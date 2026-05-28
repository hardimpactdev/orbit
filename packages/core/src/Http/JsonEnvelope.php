<?php

declare(strict_types=1);

namespace Orbit\Core\Http;

final class JsonEnvelope
{
    public static function success(array $data = [], array $meta = []): array
    {
        return [
            'success' => [
                'data' => $data,
                'meta' => $meta,
            ],
        ];
    }

    public static function failure(string $code, string $message, array $meta = []): array
    {
        return [
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
            ],
        ];
    }
}
