<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Data\RemoteShell\RemoteShellResult;
use JsonException;

final readonly class RemoteShellSuccessData
{
    /**
     * @return array<string, mixed>
     */
    public static function fromJsonEnvelope(RemoteShellResult $result): array
    {
        try {
            /** @var mixed $payload */
            $payload = json_decode(trim($result->stdout), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($payload)) {
            return [];
        }

        /** @var mixed $data */
        $data = data_get(target: $payload, key: 'success.data');

        if (! is_array($data)) {
            return [];
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function errorFromJsonEnvelope(RemoteShellResult $result): array
    {
        try {
            /** @var mixed $payload */
            $payload = json_decode(trim($result->stdout), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($payload)) {
            return [];
        }

        /** @var mixed $error */
        $error = data_get(target: $payload, key: 'error');

        if (! is_array($error)) {
            return [];
        }

        /** @var array<string, mixed> $error */
        return $error;
    }
}
