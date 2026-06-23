<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests;

use Orbit\Sdk\Laravel\GatewayStreamRequest;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Traits\Body\HasJsonBody;

final class GenericGatewayStreamRequest extends GatewayStreamRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly string $path,
        private readonly array $payload = [],
        string $method = 'post',
    ) {
        $this->method = Method::from(strtoupper($method));
    }

    public function resolveEndpoint(): string
    {
        return '/'.ltrim($this->path, '/');
    }

    public function methodName(): string
    {
        return $this->method->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'text/event-stream',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return $this->payload;
    }
}
