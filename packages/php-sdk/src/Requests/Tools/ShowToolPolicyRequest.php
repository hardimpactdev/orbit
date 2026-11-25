<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tools;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tools\ToolPolicyResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowToolPolicyRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(private readonly ?string $export = null) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/tool-policy';
    }

    /** @return array{export?: string} */
    protected function defaultQuery(): array
    {
        return $this->export === null ? [] : ['export' => $this->export];
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ToolPolicyResponse
    {
        return ToolPolicyResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
