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

    /** @param array<string, mixed>|null $repository */
    public function __construct(
        private readonly int $toolId,
        private readonly ?string $idempotencyKey = null,
        #[\SensitiveParameter]
        private readonly ?array $repository = null,
    ) {}

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    /** @return array{repository?: array<string, mixed>} */
    protected function defaultBody(): array
    {
        return $this->repository === null ? [] : ['repository' => $this->repository];
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
