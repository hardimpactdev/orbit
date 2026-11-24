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
        /** The mode of the Project's task copy groups that set none; null for the Gateway's default (docs/reference/tasks.md#task-group-modes). */
        public ?string $taskPrMode = null,
        /** Whether the Gateway sent `task_pr_mode`, so a null mode is told from an older Gateway that sends none. */
        public bool $hasTaskPrMode = false,
        /** Whether each new commit of the default branch deploys the container default; null from a Gateway that sends none. */
        public ?bool $defaultAutoDeploy = null,
        /** `t3` or `conn`: the server of the Project's new containers (docs/reference/projects.md#container-server); null from a Gateway that sends none. */
        public ?string $containerServer = null,
        /**
         * What the agents in the Project's next copies read (docs/reference/development-containers.md#agent-instructions):
         * `{source: project|type_note, agents_md_version: int|null, skills: list<string>}`; null from a Gateway or a list that sends none.
         *
         * @var array{source: string, agents_md_version: int|null, skills: list<string>}|null
         */
        public ?array $agentInstructions = null,
        /**
         * The summary a Conn client reads from the Project list (docs/reference/projects.md#project-summary); null from a show, create or update, or a Gateway that sends none.
         *
         * @var array{repository: string, node_name: string|null, icon: string|null, color: string|null, instance_count: int}|null
         */
        public ?array $summary = null,
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
            taskPrMode: is_string($data['task_pr_mode'] ?? null) ? $data['task_pr_mode'] : null,
            hasTaskPrMode: array_key_exists('task_pr_mode', $data),
            defaultAutoDeploy: is_bool($data['default_auto_deploy'] ?? null) ? $data['default_auto_deploy'] : null,
            containerServer: is_string($data['container_server'] ?? null) ? $data['container_server'] : null,
            agentInstructions: self::agentInstructions($data['agent_instructions'] ?? null),
            summary: self::summary($data),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{repository: string, node_name: string|null, icon: string|null, color: string|null, instance_count: int}|null
     */
    private static function summary(array $data): ?array
    {
        if (! is_int($data['instance_count'] ?? null)) {
            return null;
        }

        return [
            'repository' => is_string($data['repository'] ?? null) ? $data['repository'] : '',
            'node_name' => is_string($data['node_name'] ?? null) ? $data['node_name'] : null,
            'icon' => is_string($data['icon'] ?? null) ? $data['icon'] : null,
            'color' => is_string($data['color'] ?? null) ? $data['color'] : null,
            'instance_count' => $data['instance_count'],
        ];
    }

    /** @return array{source: string, agents_md_version: int|null, skills: list<string>}|null */
    private static function agentInstructions(mixed $value): ?array
    {
        if (! is_array($value) || ! is_string($value['source'] ?? null)) {
            return null;
        }
        $skills = is_array($value['skills'] ?? null) ? array_values(array_filter($value['skills'], is_string(...))) : [];

        return ['source' => $value['source'], 'agents_md_version' => is_int($value['agents_md_version'] ?? null) ? $value['agents_md_version'] : null, 'skills' => $skills];
    }

    /** The `Agent instructions` line: `project: AGENTS.md v3, skills: a, b`, or `type note`. Null when the Gateway sent none. */
    public function agentInstructionsLabel(): ?string
    {
        if ($this->agentInstructions === null) {
            return null;
        }
        if ($this->agentInstructions['source'] !== 'project') {
            return 'type note';
        }
        $parts = [...($this->agentInstructions['agents_md_version'] === null ? [] : ['agent/AGENTS.md v'.$this->agentInstructions['agents_md_version']]),
            ...($this->agentInstructions['skills'] === [] ? [] : ['skills: '.implode(', ', $this->agentInstructions['skills'])])];

        return 'project: '.implode(', ', $parts);
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
            ...($this->hasTaskPrMode ? ['task_pr_mode' => $this->taskPrMode] : []),
            ...($this->defaultAutoDeploy === null ? [] : ['default_auto_deploy' => $this->defaultAutoDeploy]),
            ...($this->containerServer === null ? [] : ['container_server' => $this->containerServer]),
            ...($this->agentInstructions === null ? [] : ['agent_instructions' => $this->agentInstructions]),
            ...($this->summary ?? []),
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
