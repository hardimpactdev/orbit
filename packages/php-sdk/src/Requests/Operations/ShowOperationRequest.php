<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Operations;

use InvalidArgumentException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/** Reads the current parent Operation. */
final class ShowOperationRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly int $operationId,
    ) {
        if ($operationId < 1) {
            throw new InvalidArgumentException('An Operation is a positive ID.');
        }
    }

    public function resolveEndpoint(): string
    {
        return "/api/v1/operations/{$this->operationId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
