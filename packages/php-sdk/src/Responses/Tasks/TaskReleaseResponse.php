<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

/** One task copy group in the `task:status` view whose pull request waits for a person's release, or whose automatic publish was refused. */
final readonly class TaskReleaseResponse
{
    public function __construct(
        public int $id,
        public int $projectId,
        public ?string $project,
        public ?string $projectCode,
        public string $title,
        public string $state,
        public ?int $checkId,
        public ?string $commit,
        public ?string $errorCode,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        return new self(
            id: TaskFields::id($data, 'id', 'task release', $requestId),
            projectId: TaskFields::id($data, 'project_id', 'task release', $requestId),
            project: TaskFields::nullableText($data, 'project'),
            projectCode: TaskFields::nullableText($data, 'project_code'),
            title: TaskFields::text($data, 'title', 'task release', $requestId),
            state: TaskFields::text($data, 'state', 'task release', $requestId),
            checkId: TaskFields::nullableInt($data, 'check_id'),
            commit: TaskFields::nullableText($data, 'commit'),
            errorCode: TaskFields::nullableText($data, 'error_code'),
        );
    }

    /** The task's human reference, such as ORB-13, or #13 when the Project has no code. */
    public function reference(): string
    {
        return $this->projectCode === null || $this->projectCode === '' ? "#{$this->id}" : "{$this->projectCode}-{$this->id}";
    }

    /** @return array{id: int, project_id: int, project: string|null, project_code: string|null, title: string, state: string, check_id: int|null, commit: string|null, error_code: string|null} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'project_id' => $this->projectId, 'project' => $this->project, 'project_code' => $this->projectCode, 'title' => $this->title,
            'state' => $this->state, 'check_id' => $this->checkId, 'commit' => $this->commit, 'error_code' => $this->errorCode];
    }
}
