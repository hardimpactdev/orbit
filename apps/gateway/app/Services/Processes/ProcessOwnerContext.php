<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Nodes\NodeHostPaths;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Orbit\Sdk\Laravel\GatewayApiException;

final readonly class ProcessOwnerContext
{
    public function __construct(
        public Node $node,
        public ?App $app,
        public ?Workspace $workspace,
        public Model $owner,
        public ?AppInstance $appInstance = null,
    ) {}

    public function runtimeApp(): App
    {
        if ($this->app instanceof App) {
            $app = ProcessRuntimeApp::make($this->app, $this->node, $this->appInstance);

            $app->setRelation('node', $this->node);

            return $app;
        }

        $home = new NodeHostPaths()->homeDirectory($this->node);

        $app = new App([
            'name' => $this->node->name,
            'path' => $home,
            'node_id' => $this->node->id,
        ]);
        $app->setRelation('node', $this->node);

        return $app;
    }

    public function defaultRuntime(): ProcessRuntime
    {
        if ($this->app instanceof App) {
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

    /**
     * @return MorphMany<Process, Node>|MorphMany<Process, App>|MorphMany<Process, Workspace>
     */
    public function ownerProcesses(): MorphMany
    {
        if ($this->owner instanceof Node || $this->owner instanceof App || $this->owner instanceof Workspace) {
            return $this->owner->processes();
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
        if ($this->workspace instanceof Workspace && $this->app instanceof App) {
            /** @var Collection<int, Process> $appProcesses */
            $appProcesses = $this->app
                ->processes()
                ->when($name !== null, fn ($query) => $query->where('name', $name))
                ->get();

            /** @var Collection<int, Process> $workspaceProcesses */
            $workspaceProcesses = $this->workspace
                ->processes()
                ->when($name !== null, fn ($query) => $query->where('name', $name))
                ->get();

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

        /** @var Collection<int, Process> $processes */
        $processes = $this
            ->ownerProcesses()
            ->when($name !== null, fn ($query) => $query->where('name', $name))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $processes;
    }

    public function runtimeWorkspaceFor(Process $process): ?Workspace
    {
        if (! $this->workspace instanceof Workspace) {
            return null;
        }

        return $this->workspace;
    }

    public function eventApp(): ?App
    {
        return $this->app;
    }

    public function subject(): Model
    {
        return $this->app ?? $this->node;
    }

    /**
     * @return array{node: string, app: string|null, workspace: string|null}
     */
    public function payloadContext(): array
    {
        return [
            'node' => $this->node->name,
            'app' => $this->app?->name,
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
                'app' => $this->app?->name,
                'workspace' => $this->workspace?->name,
                'name' => $name,
            ],
            fn (mixed $value): bool => $value !== null,
        );
    }

    public function label(): string
    {
        if ($this->workspace instanceof Workspace && $this->app instanceof App) {
            return "workspace '{$this->workspace->name}' on app '{$this->app->name}'";
        }

        if ($this->app instanceof App) {
            return "app '{$this->app->name}'";
        }

        return "node '{$this->node->name}'";
    }
}
