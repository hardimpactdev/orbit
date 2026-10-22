<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/** Starts tasks.publish for a passed handoff check. */
final class PublishTaskGroupRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /** @param array{title: string, body: string}|null $pullRequest */
    public function __construct(private readonly int $groupId, private readonly int $check, private readonly ?array $pullRequest = null, private readonly ?string $idempotencyKey = null) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/task-groups/{$this->groupId}/publish";
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    /** @return array{check: int, pull_request?: array{title: string, body: string}} */
    protected function defaultBody(): array
    {
        return $this->pullRequest === null ? ['check' => $this->check] : ['check' => $this->check, 'pull_request' => $this->pullRequest];
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
