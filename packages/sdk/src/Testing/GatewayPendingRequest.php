<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Testing;

use Saloon\Http\PendingRequest;

final readonly class GatewayPendingRequest
{
    public function __construct(
        private PendingRequest $pendingRequest,
    ) {}

    public function method(): string
    {
        return $this->pendingRequest->getMethod()->value;
    }

    public function url(): string
    {
        return $this->pendingRequest->getUrl();
    }

    public function header(string $name): ?string
    {
        $value = $this->pendingRequest->headers()->get($name);

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        $body = $this->pendingRequest->body()?->all() ?? [];

        return is_array($body) ? $body : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->pendingRequest->config()->all();
    }

    public function configValue(string $name): mixed
    {
        return $this->pendingRequest->config()->get($name);
    }
}
