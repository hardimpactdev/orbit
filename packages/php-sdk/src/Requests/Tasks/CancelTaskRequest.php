<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Orbit\Sdk\Responses\Tasks\TaskCancellationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/** `discard`: the cancel may destroy a task copy that holds work origin lacks, or that cannot be read. */
final class CancelTaskRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $groupId,
        private readonly string $idempotencyKey,
        private readonly bool $discard = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/tasks/{$this->groupId}/cancel";
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return ['Idempotency-Key' => $this->idempotencyKey];
    }

    /** @return array{discard?: true} */
    protected function defaultBody(): array
    {
        return $this->discard ? ['discard' => true] : [];
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskCancellationResponse
    {
        return new TaskCancellationResponse($response->status() === 202
            ? OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response))
            : SubtaskResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response)));
    }
}
