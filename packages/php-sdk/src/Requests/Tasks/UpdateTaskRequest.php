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

final class UpdateTaskRequest extends GatewayRequest implements HasBody
{
    use HasStringBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    /**
     * @param  list<array<string, mixed>>|null  $deliverables
     * @param  string|null  $reason  why a correction after a failed check replaces a started subtask's deliverables in a task copy
     * @param  string|null  $workStatus  the status of the work of a task with no subtask (docs/reference/tasks.md#a-task-with-no-subtask)
     */
    public function __construct(
        private readonly int $groupId,
        private readonly ?string $title = null,
        private readonly ?string $brief = null,
        private readonly ?string $status = null,
        private readonly ?bool $preview = null,
        private readonly ?int $position = null,
        private readonly ?array $deliverables = null,
        private readonly ?TaskAgentsInput $agents = null,
        private readonly ?string $prMode = null,
        private readonly ?string $reason = null,
        private readonly ?string $workStatus = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/tasks/{$this->groupId}";
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
                'title' => $this->title,
                'brief' => $this->brief,
                'status' => $this->status,
                'preview' => $this->preview,
                'pr_mode' => $this->prMode,
                'position' => $this->position,
                'deliverables' => $this->deliverables,
                'reason' => $this->reason,
                'work_status' => $this->workStatus,
            ],
            static fn (mixed $value): bool => $value !== null,
        ) + ($this->agents?->toArray() ?? []);

        return json_encode($body === [] ? new \stdClass : $body, JSON_THROW_ON_ERROR);
    }
}
