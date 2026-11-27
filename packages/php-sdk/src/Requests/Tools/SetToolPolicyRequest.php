<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tools;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tools\ToolPolicyResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class SetToolPolicyRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PUT;

    public function __construct(private readonly string $mode, private readonly ?string $catalogPath = null) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/tool-policy';
    }

    /** @return array{mode: string, catalog_path?: string} */
    protected function defaultBody(): array
    {
        return ['mode' => $this->mode, ...($this->catalogPath === null ? [] : ['catalog_path' => $this->catalogPath])];
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ToolPolicyResponse
    {
        return ToolPolicyResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
