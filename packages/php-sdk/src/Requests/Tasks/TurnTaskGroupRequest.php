<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasStringBody;

/** Starts tasks.turn and returns the queued operation. */
final class TurnTaskGroupRequest extends GatewayRequest implements HasBody
{
    use HasStringBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $groupId,
        private readonly int $subtask,
        private readonly string $role,
        private readonly string $prompt,
        private readonly string $thread,
        private readonly ?string $model = null,
        private readonly ?int $check = null,
        private readonly ?string $idempotencyKey = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/task-groups/{$this->groupId}/turn";
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return ['Content-Type' => 'application/json', ...($this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey])];
    }

    protected function defaultBody(): string
    {
        return json_encode(array_filter([
            'subtask' => $this->subtask, 'role' => $this->role, 'prompt' => $this->prompt,
            'thread' => $this->thread, 'model' => $this->model, 'check' => $this->check,
        ], static fn (mixed $value): bool => $value !== null), JSON_THROW_ON_ERROR);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
