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
        /** @var array<string, string|null> the copy turn agent defaults, keyed `implementer_provider` … without the `task_` prefix; empty from a Gateway that sends none */
        public array $taskAgents = [],
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
            taskAgents: self::taskAgents($data),
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
            ...array_combine(array_map(static fn (string $key): string => 'task_'.$key, array_keys($this->taskAgents)), $this->taskAgents),
            'request_id' => $this->requestId,
        ];
    }

    /** The role's default as `claude / claude-opus-5-5 / medium`; unset values read `-`, and a role with none set is null. */
    public function taskAgentLabel(string $role): ?string
    {
        $values = array_map(fn (string $value): ?string => $this->taskAgents[$role.'_'.$value] ?? null, ['provider', 'model', 'effort']);

        return array_filter($values, static fn (?string $value): bool => $value !== null) === []
            ? null
            : implode(' / ', array_map(static fn (?string $value): string => $value ?? '-', $values));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private static function taskAgents(array $data): array
    {
        $agents = [];
        foreach (['implementer', 'reviewer'] as $role) {
            foreach (['provider', 'model', 'effort'] as $value) {
                $key = $role.'_'.$value;
                if (array_key_exists('task_'.$key, $data)) {
                    $agents[$key] = is_string($data['task_'.$key]) ? $data['task_'.$key] : null;
                }
            }
        }

        return $agents;
    }
}
