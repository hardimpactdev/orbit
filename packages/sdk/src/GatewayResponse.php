<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel;

use Saloon\Http\Response;

final readonly class GatewayResponse
{
    /**
     * @param  array<string, mixed>  $headers
     */
    public function __construct(
        private int $status,
        private string $body,
        private array $headers = [],
    ) {}

    public static function fromSaloonResponse(Response $response): self
    {
        return new self(
            status: $response->status(),
            body: $response->body(),
            headers: $response->headers()->all(),
        );
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        $value = $this->headers[$name] ?? $this->headers[strtolower($name)] ?? null;

        if (is_array($value)) {
            $first = reset($value);

            return is_scalar($first) ? (string) $first : null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
