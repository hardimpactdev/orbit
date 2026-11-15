<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasStringBody;

/** Starts a turn in the group's task copy and returns the queued operation. A null thread sends none: the turn then continues the role's thread. */
final class TurnTaskRequest extends GatewayRequest implements HasBody
{
    use HasStringBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $taskId,
        private readonly string $role,
        private readonly string $prompt,
        private readonly ?string $thread,
        private readonly ?int $check = null,
        private readonly ?string $idempotencyKey = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/tasks/{$this->taskId}/turn";
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return ['Content-Type' => 'application/json', ...($this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey])];
    }

    protected function defaultBody(): string
    {
        return json_encode(array_filter([
            'role' => $this->role, 'prompt' => $this->prompt,
            'thread' => $this->thread, 'check' => $this->check,
        ], static fn (mixed $value): bool => $value !== null), JSON_THROW_ON_ERROR);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
