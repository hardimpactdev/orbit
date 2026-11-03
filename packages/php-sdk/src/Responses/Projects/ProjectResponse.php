<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Projects;

use Orbit\Sdk\Support\CredentialRedactor;
use SensitiveParameter;

final readonly class ProjectResponse
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public string $type,
        public string $repositoryUrl,
        public ?string $defaultBranch,
        public ?string $root,
        public string $requestId,
        public ?string $taskCheck = null,
        public string $sourceAccess = 'github_app',
        public ?bool $taskWorkspaceRouted = null,
        public ?bool $reviewAndMerge = null,
        public ?string $mergeCheck = null,
        public ?bool $maintenance = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $redactor = new CredentialRedactor;

        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            slug: is_string($data['slug'] ?? null) ? $data['slug'] : '',
            type: is_string($data['type'] ?? null) ? $data['type'] : 'laravel-app',
            repositoryUrl: is_string($data['repository_url'] ?? null)
                ? $redactor->redactText($data['repository_url'])
                : '',
            defaultBranch: is_string($data['default_branch'] ?? null) ? $data['default_branch'] : null,
            root: is_string($data['root'] ?? null) ? $data['root'] : null,
            requestId: $requestId,
            taskCheck: is_string($data['task_check'] ?? null) ? $data['task_check'] : null,
            sourceAccess: is_string($data['source_access'] ?? null) ? $data['source_access'] : 'github_app',
            taskWorkspaceRouted: is_bool($data['task_workspace_routed'] ?? null) ? $data['task_workspace_routed'] : null,
            reviewAndMerge: is_bool($data['review_and_merge'] ?? null) ? $data['review_and_merge'] : null,
            mergeCheck: is_string($data['merge_check'] ?? null) ? $data['merge_check'] : null,
            maintenance: is_bool($data['maintenance'] ?? null) ? $data['maintenance'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'repository_url' => $this->repositoryUrl,
            'source_access' => $this->sourceAccess,
            'default_branch' => $this->defaultBranch,
            'root' => $this->root,
            'task_check' => $this->taskCheck,
            ...($this->taskWorkspaceRouted === null ? [] : ['task_workspace_routed' => $this->taskWorkspaceRouted]),
            ...($this->reviewAndMerge === null ? [] : ['review_and_merge' => $this->reviewAndMerge, 'merge_check' => $this->mergeCheck]),
            ...($this->maintenance === null ? [] : ['maintenance' => $this->maintenance]),
            'request_id' => $this->requestId,
        ];
    }
}
