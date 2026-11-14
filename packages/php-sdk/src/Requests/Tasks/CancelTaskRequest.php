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
use Saloon\Traits\Body\HasStringBody;

/**
 * `discard`: the cancel may destroy a task copy that holds work origin lacks, or that cannot be read.
 * The body is always a JSON object: `{}` without discard, `{"discard":true}` with it.
 */
final class CancelTaskRequest extends GatewayRequest implements HasBody
{
    use HasStringBody;

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
        return ['Content-Type' => 'application/json', 'Idempotency-Key' => $this->idempotencyKey];
    }

    protected function defaultBody(): string
    {
        return json_encode($this->discard ? ['discard' => true] : new \stdClass, JSON_THROW_ON_ERROR);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskCancellationResponse
    {
        return new TaskCancellationResponse($response->status() === 202
            ? OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response))
            : SubtaskResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response)));
    }
}
