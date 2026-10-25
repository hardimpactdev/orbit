<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/** Starts tasks.script; its nonzero script outcome is still a successful operation. */
final class ScriptTaskGroupRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /** @param list<string> $args */
    public function __construct(private readonly int $groupId, private readonly int $subtask, private readonly string $script, private readonly array $args, private readonly string $idempotencyKey) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/task-groups/{$this->groupId}/script";
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return ['Idempotency-Key' => $this->idempotencyKey];
    }

    /** @return array{subtask: int, script: string, args: list<string>} */
    protected function defaultBody(): array
    {
        return ['subtask' => $this->subtask, 'script' => $this->script, 'args' => $this->args];
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
