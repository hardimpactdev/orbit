<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Operations;

use InvalidArgumentException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Repositories\Body\JsonBodyRepository;
use Saloon\Traits\Body\HasJsonBody;

/** Asks an operation to stop. The Gateway answers at once; a running operation stops before its next step. */
final class CancelOperationRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody {
        body as private jsonBody;
    }

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(public readonly int $operationId)
    {
        if ($operationId < 1) {
            throw new InvalidArgumentException('An operation is a positive ID.');
        }
    }

    public function body(): JsonBodyRepository
    {
        return $this->jsonBody()->setJsonFlags(JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
    }

    public function resolveEndpoint(): string
    {
        return "/api/v1/operations/{$this->operationId}/cancel";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<never, never> */
    protected function defaultBody(): array
    {
        return [];
    }
}
