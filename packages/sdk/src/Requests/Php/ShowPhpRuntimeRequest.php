<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Php;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Php\PhpRuntimeResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowPhpRuntimeRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly ?string $instance = null,
        public readonly ?string $workspace = null,
        public readonly ?string $node = null,
        public readonly bool $live = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/php/runtime';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter(
            [
                'instance' => $this->instance,
                'workspace' => $this->workspace,
                'node' => $this->node,
                'live' => $this->live ? true : null,
            ],
            static fn (mixed $value): bool => $value !== null,
        );
    }

    public function createDtoFromResponse(Response $response): PhpRuntimeResponse
    {
        $data = $this->unwrapData($response);

        return new PhpRuntimeResponse(
            php: $this->stringKeyedArray($data['php'] ?? []),
            meta: $this->unwrapMeta($response),
        );
    }
}
