<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Repositories\Body\JsonBodyRepository;
use Saloon\Traits\Body\HasJsonBody;

/** Starts tasks.publish for a passed handoff check, or, without a check, releases a task copy's done check. */
final class PublishTaskGroupRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody {
        body as private jsonBody;
    }

    #[\Override]
    protected Method $method = Method::POST;

    /** @param array{title: string, body: string, draft?: bool}|null $pullRequest */
    public function __construct(private readonly int $groupId, private readonly ?int $check, private readonly ?array $pullRequest = null, private readonly ?string $idempotencyKey = null) {}

    /** A release sends the empty object `{}`, never the list `[]`. */
    public function body(): JsonBodyRepository
    {
        return $this->jsonBody()->setJsonFlags(JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
    }

    public function resolveEndpoint(): string
    {
        return "/api/v1/tasks/{$this->groupId}/publish";
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    /** @return array{check?: int, pull_request?: array{title: string, body: string, draft?: bool}} */
    protected function defaultBody(): array
    {
        return [...($this->check === null ? [] : ['check' => $this->check]), ...($this->pullRequest === null ? [] : ['pull_request' => $this->pullRequest])];
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
