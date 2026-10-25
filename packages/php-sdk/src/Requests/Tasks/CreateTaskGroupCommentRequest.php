<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TaskCommentResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasStringBody;

final class CreateTaskGroupCommentRequest extends GatewayRequest implements HasBody
{
    use HasStringBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $groupId,
        private readonly string $type,
        private readonly string $commentBody,
        private readonly string $author,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/task-groups/{$this->groupId}/comments";
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskCommentResponse
    {
        return TaskCommentResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    protected function defaultBody(): string
    {
        return json_encode([
            'type' => $this->type,
            'body' => $this->commentBody,
            'author' => $this->author,
        ], JSON_THROW_ON_ERROR);
    }
}
