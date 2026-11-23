<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Conn;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Conn\ConnPushCredentialsResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

/** Stores Apple APNs provider credentials on the Gateway. The private key is never returned or logged. */
final class SetConnPushCredentialsRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PUT;

    public function __construct(
        private readonly string $keyId,
        private readonly string $teamId,
        #[SensitiveParameter]
        private readonly string $privateKey,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/conn/push-credentials';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): ConnPushCredentialsResponse
    {
        return ConnPushCredentialsResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array{key_id: string, team_id: string, private_key: string} */
    protected function defaultBody(): array
    {
        return [
            'key_id' => $this->keyId,
            'team_id' => $this->teamId,
            'private_key' => $this->privateKey,
        ];
    }
}
