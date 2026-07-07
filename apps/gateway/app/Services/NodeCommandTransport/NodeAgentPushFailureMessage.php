<?php

declare(strict_types=1);

namespace App\Services\NodeCommandTransport;

use Illuminate\Http\Client\Response;
use SensitiveParameter;

final readonly class NodeAgentPushFailureMessage
{
    public static function forResponse(
        string $prefix,
        Response $response,
        #[SensitiveParameter]
        string $operationToken,
    ): string {
        $message = "{$prefix} with HTTP {$response->status()}";
        $body = self::summarizeBody($response, $operationToken);

        if ($body === '') {
            return "{$message}.";
        }

        return "{$message}: {$body}";
    }

    private static function summarizeBody(
        Response $response,
        #[SensitiveParameter]
        string $operationToken,
    ): string {
        $body = trim($response->body());

        if ($body === '') {
            return '';
        }

        $body = str_replace(
            search: $operationToken,
            replace: '[redacted-operation-token]',
            subject: $body,
        );
        $body = preg_replace(pattern: '/\s+/', replacement: ' ', subject: $body) ?? $body;

        if (strlen($body) <= 512) {
            return $body;
        }

        return substr(string: $body, offset: 0, length: 512).'...';
    }
}
