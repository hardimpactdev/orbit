<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Vault;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Vault\VaultStatusResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class ShowVaultStatusRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/vault';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): VaultStatusResponse
    {
        return VaultStatusResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
