<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Operations;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Orbit\Sdk\Responses\Operations\OperationsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/** Lists the 50 newest operations the caller can access, newest first, without steps. */
final class ListOperationsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly ?string $status = null,
        private readonly ?string $kind = null,
        private readonly ?string $targetType = null,
        private readonly ?int $targetId = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/operations';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationsResponse
    {
        $requestId = $this->successRequestId($response);

        return new OperationsResponse(
            array_map(
                static fn (array $operation): OperationResponse => OperationResponse::fromGatewayData($operation, $requestId),
                $this->unwrapDataList($response, rejectMalformed: true),
            ),
            $requestId,
        );
    }

    /** @return array<string, int|string> */
    protected function defaultQuery(): array
    {
        return array_filter([
            'status' => $this->status,
            'kind' => $this->kind,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
        ], static fn (int|string|null $value): bool => $value !== null);
    }
}
