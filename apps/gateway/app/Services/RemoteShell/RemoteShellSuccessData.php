<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Data\RemoteShell\RemoteShellResult;
use App\Services\RemoteShell\Exceptions\RemoteShellProtocolException;
use JsonException;

final readonly class RemoteShellSuccessData
{
    /**
     * @return array<string, mixed>
     */
    public static function fromJsonEnvelope(RemoteShellResult $result): array
    {
        try {
            return self::fromJsonEnvelopeOrFail($result);
        } catch (RemoteShellProtocolException) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RemoteShellProtocolException
     */
    public static function fromJsonEnvelopeOrFail(RemoteShellResult $result): array
    {
        $stdout = trim($result->stdout);

        if ($stdout === '') {
            throw new RemoteShellProtocolException('Remote shell success envelope output is empty.');
        }

        try {
            /** @var mixed $payload */
            $payload = json_decode($stdout, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RemoteShellProtocolException(
                "Remote shell success envelope contains malformed JSON: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        if (! is_array($payload)) {
            throw new RemoteShellProtocolException('Remote shell success envelope must be a JSON object.');
        }

        /** @var mixed $success */
        $success = $payload['success'] ?? null;

        if (! is_array($success) || ! array_key_exists('data', $success)) {
            throw new RemoteShellProtocolException('Remote shell success envelope is missing success.data.');
        }

        /** @var mixed $data */
        $data = $success['data'];

        if (! is_array($data)) {
            throw new RemoteShellProtocolException('Remote shell success envelope success.data must be an object.');
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
