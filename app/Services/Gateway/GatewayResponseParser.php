<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use Illuminate\Http\Client\Response;
use RuntimeException;

final readonly class GatewayResponseParser
{
    public function parse(Response $response): GatewayResponse
    {
        if ($response->failed()) {
            throw new RuntimeException(
                "Gateway request failed with HTTP status {$response->status()}",
            );
        }

        $body = $response->body() ?: '';

        if ($body === '') {
            throw new RuntimeException('Gateway response body is empty.');
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Gateway response is not valid JSON.');
        }

        if (array_key_exists('doctor', $payload)) {
            return GatewayResponse::success(data: $payload);
        }

        if (array_key_exists('success', $payload)) {
            $success = $payload['success'];

            if (is_array($success)) {
                $data = $success['data'] ?? [];
                $meta = $success['meta'] ?? [];

                return GatewayResponse::success(
                    data: is_array($data) ? $data : [],
                    meta: is_array($meta) ? $meta : [],
                );
            }

            if ($success === true) {
                $data = $payload['data'] ?? [];
                $meta = $payload['meta'] ?? [];

                return GatewayResponse::success(
                    data: is_array($data) ? $data : [],
                    meta: is_array($meta) ? $meta : [],
                );
            }
        }

        if (array_key_exists('error', $payload)) {
            $error = $payload['error'];

            if (is_array($error)) {
                $message = $error['message'] ?? null;
                $code = $error['code'] ?? null;
                $meta = $error['meta'] ?? null;

                if (! is_string($message) || $message === '') {
                    throw new RuntimeException('Gateway error response has no message.');
                }

                return GatewayResponse::error(
                    code: is_string($code) ? $code : null,
                    message: $message,
                    errorMeta: is_array($meta) ? $meta : [],
                );
            }

            if (is_string($error) && $error !== '') {
                return GatewayResponse::error(message: $error);
            }
        }

        return GatewayResponse::success(data: $payload);
    }
}
