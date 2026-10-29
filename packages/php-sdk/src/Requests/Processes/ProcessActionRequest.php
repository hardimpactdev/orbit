<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Processes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Processes\ProcessMutationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

abstract class ProcessActionRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        protected readonly int $processId,
        private readonly ?string $idempotencyKey = null,
    ) {}

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    final public function resolveEndpoint(): string
    {
        return "/api/v1/processes/{$this->processId}/{$this->action()}";
    }

    final public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProcessMutationResponse
    {
        $requestId = $this->successRequestId($response);

        return ProcessMutationResponse::fromGatewayData($this->unwrapData($response), $requestId, $response->status());
    }

    abstract protected function action(): string;
}
