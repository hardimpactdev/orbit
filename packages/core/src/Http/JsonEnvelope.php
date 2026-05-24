<?php

declare(strict_types=1);

namespace Orbit\Core\Http;

final class JsonEnvelope
{
    public static function success(array $data = [], array $meta = []): array
    {
        return [
            'ok' => true,
            'data' => $data,
            'meta' => $meta,
        ];
    }

    public static function failure(string $code, string $message, array $meta = []): array
    {
        return [
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'meta' => $meta,
        ];
    }
}
