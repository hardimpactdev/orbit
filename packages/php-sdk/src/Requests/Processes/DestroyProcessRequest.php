<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Processes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Processes\ProcessMutationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class DestroyProcessRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $processId,
        private readonly ?string $idempotencyKey = null,
    ) {}

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    public function resolveEndpoint(): string
    {
        return "/api/v1/processes/{$this->processId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProcessMutationResponse
    {
        $requestId = $this->successRequestId($response);

        return ProcessMutationResponse::fromGatewayData($this->unwrapData($response), $requestId, $response->status());
    }
}
