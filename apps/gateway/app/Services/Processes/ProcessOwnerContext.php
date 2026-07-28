<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Enums\Processes\ProcessRuntime;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Nodes\NodeHostPaths;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Orbit\Sdk\Laravel\GatewayApiException;

/** @mago-expect lint:kan-defect */
final readonly class ProcessOwnerContext
{
    public function __construct(
        public Node $node,
        public ?Project $app,
        public ?Workspace $workspace,
        public Model $owner,
        public ?AppInstance $appInstance = null,
    ) {}

    public function runtimeApp(): Project
    {
        if ($this->app instanceof Project) {
            $app = ProcessRuntimeApp::make($this->app, $this->node, $this->appInstance);

            $app->setRelation('node', $this->node);

            return $app;
        }

        $home = new NodeHostPaths()->homeDirectory($this->node);

        $app = new Project([
            'name' => $this->node->name,
            'path' => $home,
            'node_id' => $this->node->id,
        ]);
        $app->setRelation('node', $this->node);

        return $app;
    }

    public function defaultRuntime(): ProcessRuntime
    {
        if ($this->app instanceof Project) {
            return ProcessRuntime::defaultForApp($this->app);
        }

        if (NodeHostPaths::isMacosPlatform($this->node->platform)) {
            return ProcessRuntime::Launchd;
        }

        return ProcessRuntime::Systemd;
    }

    public function allowsRuntime(ProcessRuntime $runtime): bool
    {
        if (! $this->runtimeSupportedByNode($runtime)) {
            return false;
        }

        if ($this->owner instanceof Node) {
            return true;
        }

        return $runtime->appWorkspaceCommandViolationReason() === null;
    }

    public function assertRuntimeAllowed(ProcessRuntime $runtime): void
    {
        if (! $this->runtimeSupportedByNode($runtime)) {
            throw new GatewayApiException(
                $this->nodeRuntimeViolationMessage($runtime),
                'validation_failed',
                [
                    'field' => 'runtime',
                    'value' => $runtime->value,
                    'reason' => $this->nodeRuntimeViolationReason($runtime),
                ],
            );
        }

        if ($this->allowsRuntime($runtime)) {
            return;
        }

        throw new GatewayApiException(
            $runtime->appWorkspaceCommandViolationMessage()
            ?? 'The selected runtime is not valid for this process owner.',
            'validation_failed',
            [
                'field' => 'runtime',
                'value' => $runtime->value,
                'reason' => $runtime->appWorkspaceCommandViolationReason(),
            ],
        );
    }

    private function runtimeSupportedByNode(ProcessRuntime $runtime): bool
    {
        if ($runtime === ProcessRuntime::Launchd) {
            return NodeHostPaths::isMacosPlatform($this->node->platform);
        }

        if (! NodeHostPaths::isMacosPlatform($this->node->platform)) {
            return true;
        }

        return ! in_array($runtime, [ProcessRuntime::DockerSwarm, ProcessRuntime::Systemd], strict: true);
    }

    private function nodeRuntimeViolationReason(ProcessRuntime $runtime): string
    {
        return match ($runtime) {
            ProcessRuntime::Launchd => 'launchd_runtime_requires_macos',
            ProcessRuntime::DockerSwarm => 'docker_swarm_runtime_requires_linux',
            ProcessRuntime::Systemd => 'systemd_runtime_requires_linux',
            ProcessRuntime::Docker => 'runtime_unsupported_on_node',
        };
    }

    private function nodeRuntimeViolationMessage(ProcessRuntime $runtime): string
    {
        return match ($runtime) {
            ProcessRuntime::Launchd => 'The launchd runtime is only supported on macOS nodes.',
            ProcessRuntime::DockerSwarm => 'The docker-swarm runtime is only supported on Linux nodes.',
            ProcessRuntime::Systemd => 'The systemd runtime is only supported on Linux nodes.',
            ProcessRuntime::Docker => 'The selected runtime is not supported on this node.',
        };
    }

    public function ownerProcesses(): MorphMany
    {
        if ($this->owner instanceof Node || $this->owner instanceof Project || $this->owner instanceof Workspace) {
            $processes = $this->owner->processes();

            if ($this->app instanceof Project) {
                if (! $this->appInstance instanceof AppInstance) {
                    throw new GatewayApiException(
                        'A concrete instance is required for project and workspace process ownership.',
                        'validation_failed',
                        [
                            'field' => 'instance',
                            'reason' => 'instance_required',
                            'project' => $this->app->name,
                        ],
                    );
                }

                $processes->getQuery()->where('app_instance_id', $this->appInstance->id);
            }

            return $processes;
        }

        throw new GatewayApiException('Process owner is not lifecycle-addressable.', 'validation_failed', [
            'field' => 'context',
        ]);
    }

    /**
     * @return Collection<int, Process>
     */
    public function lifecycleProcesses(?string $name): Collection
    {
        if ($this->workspace instanceof Workspace && $this->app instanceof Project) {
            return $this->effectiveWorkspaceProcesses($name);
        }

        /** @var Collection<int, Process> $processes */
        $processes = $this
            ->ownerProcesses()
            ->when($name !== null, fn ($query) => $query->where('name', $name))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $processes;
    }

    /**
     * @return Collection<int, Process>
     */
    public function effectiveWorkspaceProcesses(?string $name = null): Collection
    {
        return $this->workspaceProcessesForLifecycle($name);
    }

    /**
     * @return Collection<int, Process>
     */
    public function effectiveWorkspaceProcessesWithoutRuntime(?string $name = null): Collection
    {
        return $this->workspaceProcessesForLifecycleWithoutRuntime($name);
    }

    /**
     * @return Collection<int, Process>
     */
    private function workspaceProcessesForLifecycle(?string $name): Collection
    {
        if (! $this->workspace instanceof Workspace || ! $this->app instanceof Project) {
            /** @var Collection<int, Process> */
            return new Collection;
        }

        /** @var Collection<int, Process> $workspaceProcesses */
        $workspaceProcesses = $this->workspace
            ->processes()
            ->where('app_instance_id', $this->appInstance?->id)
            ->when($name !== null, fn ($query) => $query->where('name', $name))
            ->get();

        /** @var Collection<int, Process> $appProcesses */
        $appProcesses = $this->app
            ->processes()
            ->where('app_instance_id', $this->appInstance?->id)
            ->when($name !== null, fn ($query) => $query->where('name', $name))
            ->get();

        $appProcesses = $appProcesses
            ->reject(static fn (Process $process): bool => $process->runtime === ProcessRuntime::Docker)
            ->values();

        /** @var Collection<int, Process> $processes */
        $processes = new Collection(
            $appProcesses
                ->concat($workspaceProcesses)
                ->sortBy([
                    ['sort_order', 'asc'],
                    ['id', 'asc'],
                ])
                ->values()
                ->all(),
        );

        return $processes;
    }

    /**
     * @return Collection<int, Process>
     */
    private function workspaceProcessesForLifecycleWithoutRuntime(?string $name): Collection
    {
        /** @var Collection<int, Process> $processes */
        $processes = $this
            ->effectiveWorkspaceProcesses($name)
            ->reject(static fn (Process $process): bool => $process->runtime === ProcessRuntime::Docker)
            ->values();

        return $processes;
    }

    public function runtimeWorkspaceFor(Process $process): ?Workspace
    {
        if (! $this->workspace instanceof Workspace) {
            return null;
        }

        return $this->workspace;
    }

    public function eventApp(): ?Project
    {
        return $this->app;
    }

    public function subject(): Model
    {
        return $this->appInstance ?? $this->node;
    }

    /**
     * @return array{node: string, project: string|null, instance: string|null, workspace: string|null}
     */
    public function payloadContext(): array
    {
        return [
            'node' => $this->node->name,
            'project' => $this->app?->name,
            'instance' => $this->appInstance?->name,
            'workspace' => $this->workspace?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function processPayload(Process $process): array
    {
        return [
            'name' => $process->name,
            ...$this->payloadContext(),
            'command' => $process->command,
            'restart_policy' => $process->restart_policy->value,
            'crash_notification' => $process->crash_notification->value,
            'runtime' => $process->runtime->value,
            'tool' => $process->tool,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function errorMeta(?string $name = null): array
    {
        return array_filter(
            [
                'node' => $this->node->name,
                'project' => $this->app?->name,
                'instance' => $this->appInstance?->name,
                'workspace' => $this->workspace?->name,
                'name' => $name,
            ],
            static fn (mixed $value): bool => $value !== null,
        );
    }

    public function label(): string
    {
        if ($this->workspace instanceof Workspace && $this->app instanceof Project) {
            return "workspace '{$this->workspace->name}' on instance '{$this->appIdentity()}'";
        }

        if ($this->app instanceof Project) {
            return "instance '{$this->appIdentity()}'";
        }

        return "node '{$this->node->name}'";
    }

    private function appIdentity(): string
    {
        if (! $this->app instanceof Project) {
            return '';
        }

        return $this->appInstance instanceof AppInstance
            ? "{$this->app->name}.{$this->appInstance->name}"
            : $this->app->name;
    }
}
