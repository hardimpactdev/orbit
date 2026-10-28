<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Vault;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

final class SetupVaultRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PUT;

    public function __construct(
        private readonly string $url,
        private readonly string $clientId,
        #[SensitiveParameter]
        private readonly string $clientSecret,
        #[SensitiveParameter]
        private readonly string $masterPassword,
        private readonly string $organization,
        private readonly string $collection,
        private readonly ?string $idempotencyKey = null,
    ) {}

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/vault';
    }

    /**
     * @return array{
     *     url: string,
     *     client_id: string,
     *     client_secret: string,
     *     master_password: string,
     *     organization: string,
     *     collection: string
     * }
     */
    protected function defaultBody(): array
    {
        return [
            'url' => $this->url,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'master_password' => $this->masterPassword,
            'organization' => $this->organization,
            'collection' => $this->collection,
        ];
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
