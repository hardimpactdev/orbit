<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Operations;

use InvalidArgumentException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/** Reads one operation. With `afterSequence`, the Gateway returns only the steps that changed after it. */
final class ShowOperationRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly int $operationId,
        public readonly ?int $afterSequence = null,
    ) {
        if ($operationId < 1 || ($afterSequence !== null && $afterSequence < 0)) {
            throw new InvalidArgumentException('An operation is a positive ID, and after_sequence is not negative.');
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

    /** @return array<string, int> */
    protected function defaultQuery(): array
    {
        return $this->afterSequence === null ? [] : ['after_sequence' => $this->afterSequence];
    }
}
