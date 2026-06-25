<?php

declare(strict_types=1);

namespace App\Commands\Php;

use App\Exceptions\GatewayApiException;
use RuntimeException;

final class PhpUseHumanGatewayCaller
{
    /**
     * @param  callable(): array<string, mixed>  $call
     * @return array<string, mixed>
     */
    public static function call(callable $call): array
    {
        try {
            $response = $call();
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                self::failureMessage($exception),
                previous: $exception,
            );
        }

        return self::rejectEnvelopeError($response);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private static function rejectEnvelopeError(array $response): array
    {
        $error = $response['error'] ?? null;

        if (! is_array($error)) {
            return $response;
        }

        $message = $error['message'] ?? null;

        throw new RuntimeException(
            is_string($message) && trim($message) !== ''
                ? trim($message)
                : 'Gateway request failed.',
        );
    }

    private static function failureMessage(GatewayApiException $exception): string
    {
        $message = $exception->gatewayErrorMessage() ?? $exception->getMessage();

        return trim($message) !== '' ? trim($message) : 'Gateway request failed.';
    }
}
