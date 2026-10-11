<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Vault;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Vault\VaultStatusResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class SyncVaultRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/api/v1/vault/sync';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): VaultStatusResponse
    {
        return VaultStatusResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
