<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Schedules;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Schedules\ScheduleMutationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class DestroyScheduleRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        #[SensitiveParameter]
        private readonly string $scheduleId,
        private readonly ?string $idempotencyKey = null,
    ) {}

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/schedules/'.rawurlencode($this->scheduleId);
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): ScheduleMutationResponse
    {
        return ScheduleMutationResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
            $response->status(),
        );
    }
}
