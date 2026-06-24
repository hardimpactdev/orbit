<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Vpn;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Vpn\VpnClientResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class RemoveVpnClientRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        public readonly string $name,
        public readonly bool $force = true,
        public readonly ?string $totp = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/vpn/clients/{$this->name}";
    }

    protected function defaultQuery(): array
    {
        return array_filter(
            [
                'force' => $this->force,
                'totp' => $this->totp,
            ],
            fn (mixed $value): bool => $value !== null,
        );
    }

    public function createDtoFromResponse(Response $response): VpnClientResponse
    {
        $data = $this->unwrapData($response);

        return new VpnClientResponse(
            client: $this->stringKeyedArray($data['client'] ?? []),
            meta: $this->unwrapMeta($response),
        );
    }
}
