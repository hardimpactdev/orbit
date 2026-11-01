<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Analytics;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Analytics\AnalyticsCredentialsResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

/** Stores protected Plausible credentials. Values are never returned. */
final class SetAnalyticsCredentialsRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PUT;

    public function __construct(
        #[SensitiveParameter]
        private readonly string $apiKey,
        #[SensitiveParameter]
        private readonly ?string $managementEmail = null,
        #[SensitiveParameter]
        private readonly ?string $managementPassword = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/analytics/credentials';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): AnalyticsCredentialsResponse
    {
        return AnalyticsCredentialsResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array{api_key:string,management_email?:string,management_password?:string} */
    protected function defaultBody(): array
    {
        return ['api_key' => $this->apiKey,
            ...($this->managementEmail === null ? [] : ['management_email' => $this->managementEmail]),
            ...($this->managementPassword === null ? [] : ['management_password' => $this->managementPassword])];
    }
}
