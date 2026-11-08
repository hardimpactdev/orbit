<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasStringBody;

final class CreateTaskRequest extends GatewayRequest implements HasBody
{
    use HasStringBody;

    #[\Override]
    protected Method $method = Method::POST;

    /**
     * @param  list<SubtaskInput>|null  $subtasks
     * @param  list<array<string, mixed>>|null  $deliverables
     * @param  list<string>|null  $topology
     */
    public function __construct(
        private readonly ?int $projectId,
        private readonly string $title,
        private readonly string $brief,
        private readonly ?string $status = null,
        private readonly ?array $subtasks = null,
        private readonly ?bool $preview = null,
        private readonly ?string $orchestration = null,
        private readonly ?bool $currentRecipe = null,
        private readonly ?string $review = null,
        private readonly ?int $parentId = null,
        private readonly ?array $deliverables = null,
        private readonly ?array $topology = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/tasks';
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskGroupResponse|SubtaskResponse
    {
        $data = $this->unwrapData($response);

        return ($data['parent_id'] ?? null) === null
            ? TaskGroupResponse::fromGatewayData($data, $this->successRequestId($response))
            : SubtaskResponse::fromGatewayData($data, $this->successRequestId($response));
    }

    protected function defaultBody(): string
    {
        $body = array_filter(
            [
                'project_id' => $this->projectId,
                'parent_id' => $this->parentId,
                'title' => $this->title,
                'brief' => $this->brief,
                'deliverables' => $this->deliverables,
                'topology' => $this->topology,
                'status' => $this->status,
                'preview' => $this->preview,
                'current_recipe' => $this->currentRecipe,
                'review' => $this->review,
                'orchestration' => $this->orchestration,
                'subtasks' => $this->subtasks === null ? null : array_map(static fn (SubtaskInput $task): array => $task->toArray(), $this->subtasks),
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        return json_encode($body, JSON_THROW_ON_ERROR);
    }
}
