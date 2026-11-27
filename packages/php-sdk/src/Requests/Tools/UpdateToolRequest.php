<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tools;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateToolRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /** @param string|null $repository the name of the Node's apt repository the Tool installs from */
    public function __construct(
        private readonly int $toolId,
        private readonly ?string $idempotencyKey = null,
        #[\SensitiveParameter]
        private readonly ?string $repository = null,
        private readonly ?string $desiredVersion = null,
    ) {}

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    /** @return array{repository?: string, desired_version?: string} */
    protected function defaultBody(): array
    {
        return array_filter(['repository' => $this->repository, 'desired_version' => $this->desiredVersion], static fn (mixed $value): bool => $value !== null);
    }

    public function resolveEndpoint(): string
    {
        return "/api/v1/tools/{$this->toolId}/update";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
