<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class GatewayApiException extends RuntimeException
{
    private readonly ?string $bodyExcerpt;

    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        ?string $body = null,
        ?Throwable $previous = null,
    ) {
        $this->bodyExcerpt = self::excerpt($body);

        parent::__construct(
            self::formatMessage($message, $this->statusCode, $this->bodyExcerpt),
            0,
            $previous,
        );
    }

    public static function httpError(int $statusCode, string $body): self
    {
        return new self('Gateway request failed', $statusCode, $body);
    }

    public static function networkError(Throwable $exception): self
    {
        return new self("Gateway request failed: {$exception->getMessage()}", previous: $exception);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function bodyExcerpt(): ?string
    {
        return $this->bodyExcerpt;
    }

    private static function formatMessage(string $message, ?int $statusCode, ?string $bodyExcerpt): string
    {
        if ($statusCode !== null) {
            $message .= " (HTTP {$statusCode})";
        }

        if (is_string($bodyExcerpt) && $bodyExcerpt !== '') {
            $message .= " Body: {$bodyExcerpt}";
        }

        return $message;
    }

    private static function excerpt(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $excerpt = trim(preg_replace('/\s+/', ' ', $body) ?? $body);

        if ($excerpt === '') {
            return null;
        }

        if (strlen($excerpt) <= 500) {
            return $excerpt;
        }

        return substr($excerpt, 0, 500).'...';
    }
}
