<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Processes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Processes\ProcessMutationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class DestroyProcessRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $processId,
        private readonly ?string $idempotencyKey = null,
        private readonly ?int $instanceId = null,
    ) {}

    /**
     * The Instance of a container Process, which its destroy requires.
     *
     * @return array{instance?: int}
     */
    protected function defaultBody(): array
    {
        return $this->instanceId === null ? [] : ['instance' => $this->instanceId];
    }

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
