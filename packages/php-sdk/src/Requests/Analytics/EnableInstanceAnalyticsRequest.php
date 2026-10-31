<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Analytics;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

/** Sets the exact tracking hosts of an Instance; no hosts means the default `analytics.<instance domain>`. */
final class EnableInstanceAnalyticsRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /** @param list<string> $hosts
     * @param list<array<string,mixed>> $goals
     */
    public function __construct(
        private readonly int $instanceId,
        private readonly array $hosts = [],
        private readonly ?string $timezone = null,
        private readonly array $goals = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->instanceId}/analytics";
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array<string,mixed> */
    protected function defaultBody(): array
    {
        return [
            ...($this->hosts === [] ? [] : ['hosts' => $this->hosts]),
            ...($this->timezone === null ? [] : ['timezone' => $this->timezone]),
            ...($this->goals === [] ? [] : ['goals' => $this->goals]),
        ];
    }
}
