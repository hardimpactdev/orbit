<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class GatewayApiException extends RuntimeException
{
    private readonly ?string $bodyExcerpt;

    /**
     * @var array{code: string, message: string, meta: array<string, mixed>, data: array<string, mixed>}|null
     */
    private readonly ?array $gatewayError;

    public function __construct(
        string $message,
        private readonly GatewayApiFailureKind $failureKind = GatewayApiFailureKind::Generic,
        private readonly ?int $statusCode = null,
        ?string $body = null,
        ?Throwable $previous = null,
    ) {
        $this->bodyExcerpt = self::excerpt($body);
        $this->gatewayError = self::gatewayError($body);

        parent::__construct(
            self::formatMessage($message, $this->statusCode, $this->bodyExcerpt),
            0,
            $previous,
        );
    }

    public static function httpError(int $statusCode, string $body): self
    {
        return new self('Gateway request failed', GatewayApiFailureKind::Http, $statusCode, $body);
    }

    public static function networkError(Throwable $exception): self
    {
        return new self(
            "Gateway request failed: {$exception->getMessage()}",
            GatewayApiFailureKind::Network,
            previous: $exception,
        );
    }

    /**
     * Distinct failure mode per decision D15: surfaced when the CLI cannot reach the gateway
     * because WireGuard is down or no peer route exists.
     */
    public static function wireguardUnreachable(Throwable $exception): self
    {
        return new self(
            "Gateway unreachable over WireGuard: {$exception->getMessage()}. "
            .'Confirm the WireGuard tunnel is up and the gateway peer route is configured.',
            GatewayApiFailureKind::WireguardUnreachable,
            previous: $exception,
        );
    }

    public static function streamClosedBeforeTerminal(Throwable $exception): self
    {
        return new self(
            'Gateway progress stream closed without a terminal frame.',
            GatewayApiFailureKind::StreamClosedBeforeTerminal,
            previous: $exception,
        );
    }

    public static function streamMalformed(Throwable $exception): self
    {
        return new self(
            'Gateway progress stream returned a malformed frame.',
            GatewayApiFailureKind::StreamMalformed,
            previous: $exception,
        );
    }

    public function failureKind(): GatewayApiFailureKind
    {
        return $this->failureKind;
    }

    public function cliFailureCode(): string
    {
        return $this->failureKind->cliFailureCode();
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function bodyExcerpt(): ?string
    {
        return $this->bodyExcerpt;
    }

    public function hasGatewayError(): bool
    {
        return $this->gatewayError !== null;
    }

    public function gatewayErrorCode(): ?string
    {
        return $this->gatewayError['code'] ?? null;
    }

    public function gatewayErrorMessage(): ?string
    {
        return $this->gatewayError['message'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function gatewayErrorMeta(): array
    {
        return $this->gatewayError['meta'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function gatewayErrorData(): array
    {
        return $this->gatewayError['data'] ?? [];
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

    /**
     * @return array{code: string, message: string, meta: array<string, mixed>, data: array<string, mixed>}|null
     */
    private static function gatewayError(?string $body): ?array
    {
        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, associative: true);

        if (! is_array($decoded) || ! is_array($decoded['error'] ?? null)) {
            return null;
        }

        $error = $decoded['error'];
        $code = $error['code'] ?? null;

        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        $message = $error['message'] ?? null;
        $meta = $error['meta'] ?? [];
        $data = $error['data'] ?? [];

        return [
            'code' => trim($code),
            'message' => is_string($message) && trim($message) !== '' ? trim($message) : 'Gateway request failed.',
            'meta' => is_array($meta) ? $meta : [],
            'data' => is_array($data) ? $data : [],
        ];
    }
}
