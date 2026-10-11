<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Vault;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Vault\VaultStatusResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

final class TeardownVaultRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly bool $keepItems = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/vault';
    }

    /** @return array{keep_items: bool} */
    protected function defaultBody(): array
    {
        return ['keep_items' => $this->keepItems];
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): VaultStatusResponse
    {
        return VaultStatusResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
