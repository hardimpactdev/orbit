<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Fleet;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class MigrateDockerRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $nodeId,
        private readonly string $mode,
        private readonly bool $confirm = false,
        private readonly bool $fallback = false,
        private readonly ?string $recipe = null,
        private readonly ?string $idempotencyKey = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/fleet/nodes/{$this->nodeId}/docker-migration";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    /** @return array<string,mixed> */
    protected function defaultBody(): array
    {
        return ['mode' => $this->mode, ...($this->confirm ? ['confirm' => true] : []),
            ...($this->fallback ? ['fallback' => true] : []), ...($this->recipe === null ? [] : ['recipe' => $this->recipe])];
    }
}
