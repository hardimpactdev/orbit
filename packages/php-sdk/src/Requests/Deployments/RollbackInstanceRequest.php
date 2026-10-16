<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Deployments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Repositories\Body\JsonBodyRepository;
use Saloon\Traits\Body\HasJsonBody;

/** Starts an `instance.rollback` operation. The Gateway answers 202 with the queued operation at once. */
final class RollbackInstanceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody {
        body as private jsonBody;
    }

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $instanceId,
        private readonly string $release,
    ) {}

    public function body(): JsonBodyRepository
    {
        return $this->jsonBody()->setJsonFlags(JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
    }

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->instanceId}/rollback";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array{release: string} */
    protected function defaultBody(): array
    {
        return ['release' => $this->release];
    }
}
