<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Projects;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/** Inspect a Project's default branch for dependency updates; `open` admits the update group. */
final class InspectProjectMaintenanceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $projectId,
        private readonly bool $open = false,
        private readonly ?string $idempotencyKey = null,
    ) {}

    /** @return array<string,string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    /** @return array<string,bool> */
    protected function defaultBody(): array
    {
        return $this->open ? ['open' => true] : [];
    }

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/maintenance/inspect";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
