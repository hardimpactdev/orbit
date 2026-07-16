<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Process;
use App\Services\Convergence\ManagedFile;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\RemoteDockerSwarmService;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Tools\ToolsFixer;
use App\Services\Tools\ToolsProbe;
use RuntimeException;

/** @mago-expect lint:cyclomatic-complexity */
class RoleRuntimeConverger
{
    public function __construct(
        private readonly ?ToolsProbe $toolsProbe = null,
        private readonly ?ToolsFixer $toolsFixer = null,
        private readonly ?ProcessRuntimeDriverRegistry $processRuntimeDrivers = null,
        private readonly ?RemoteDockerSwarmService $dockerSwarm = null,
        private readonly ?RunsInternalCommands $localExecutor = null,
    ) {}

    public function convergeTool(Node $node, string $toolName): void
    {
        $tool = NodeTool::query()
            ->with('node')
            ->where('node_id', $node->id)
            ->where('name', $toolName)
            ->first();

        if (! $tool instanceof NodeTool) {
            throw new RuntimeException("Tool '{$toolName}' intent is missing on node '{$node->name}'.");
        }

        $this->repairToolDrift($tool);
        $remainingDrift = $this->toolsProbe()->diff(
            $tool,
            $this->toolsProbe()->introspect($tool),
            allowProvisioning: true,
        );

        if ($remainingDrift !== []) {
            throw new RuntimeException(
                "Tool '{$toolName}' could not be converged on node '{$node->name}': {$remainingDrift[0]->summary}",
            );
        }
    }

    public function convergeProcess(Node $node, Process $process, string $role): void
    {
        if ($process->runtime === ProcessRuntime::DockerSwarm && ! $this->dockerSwarm()->ensureManager($node)) {
            throw new RuntimeException(
                ucfirst($role).' process runtime could not initialize Docker Swarm.',
            );
        }

        $context = new ProcessOwnerContext(
            node: $node,
            app: null,
            workspace: null,
            owner: $node,
        );
        $runtimeApp = $context->runtimeApp();
        $workspace = $context->runtimeWorkspaceFor($process);
        $driver = $this->processRuntimeDrivers()->forProcess($process);
        $runtimeUnit = $driver->runtimeUnitName($runtimeApp, $process, $workspace);

        if (! $this->applyManagedFiles($node, $process)) {
            throw new RuntimeException(
                ucfirst($role)." process runtime unit '{$runtimeUnit}' managed files could not be applied.",
            );
        }

        if (! $driver->apply($node, $runtimeApp, $process, $workspace)) {
            throw new RuntimeException(
                ucfirst($role)." process runtime unit '{$runtimeUnit}' could not be rendered.",
            );
        }

        if (! $driver->start($node, $runtimeUnit)) {
            throw new RuntimeException(
                ucfirst($role)." process runtime unit '{$runtimeUnit}' could not be started.",
            );
        }
    }

    private function applyManagedFiles(Node $node, Process $process): bool
    {
        $credentials = is_array($process->credentials) ? $process->credentials : [];
        $managedFiles = is_array($credentials['managed_files'] ?? null) ? $credentials['managed_files'] : [];

        foreach ($managedFiles as $intent) {
            if (! is_array($intent)) {
                return false;
            }

            $path = $intent['path'] ?? null;
            $content = $intent['content'] ?? null;

            if (! is_string($path) || ! is_string($content)) {
                return false;
            }

            $file = new ManagedFile(
                path: $path,
                content: $content,
                mode: is_string($intent['mode'] ?? null) ? $intent['mode'] : '0600',
                directoryMode: is_string($intent['directory_mode'] ?? null)
                    ? $intent['directory_mode']
                    : '0750',
                sensitive: ($intent['sensitive'] ?? true) === true,
                localExecutor: $this->localExecutor ?? app(RunsInternalCommands::class),
            );
            $result = $file->apply($node, $file->plan($file->probe($node)));

            if (! $result->successful()) {
                return false;
            }
        }

        return true;
    }

    public function removeProcess(Node $node, Process $process, string $role): void
    {
        $context = new ProcessOwnerContext(
            node: $node,
            app: null,
            workspace: null,
            owner: $node,
        );
        $runtimeApp = $context->runtimeApp();
        $workspace = $context->runtimeWorkspaceFor($process);
        $driver = $this->processRuntimeDrivers()->forProcess($process);
        $runtimeUnit = $driver->runtimeUnitName($runtimeApp, $process, $workspace);

        if (! $driver->remove($node, $runtimeUnit)) {
            throw new RuntimeException(
                ucfirst($role)." process runtime unit '{$runtimeUnit}' could not be removed.",
            );
        }
    }

    private function repairToolDrift(NodeTool $tool): void
    {
        $drift = $this->toolsProbe()->diff(
            $tool,
            $this->toolsProbe()->introspect($tool),
            allowProvisioning: true,
        );

        foreach ($drift as $entry) {
            $result = $this->toolsFixer()->fix($tool, $entry);

            if (is_array($result) && ($result['status'] ?? null) === 'completed') {
                continue;
            }

            $summary = is_array($result) && is_string($result['summary'] ?? null)
                ? $result['summary']
                : $entry->summary;

            throw new RuntimeException(
                "Tool '{$tool->name}' could not be converged on node '{$tool->node?->name}': {$summary}",
            );
        }
    }

    private function toolsProbe(): ToolsProbe
    {
        return $this->toolsProbe ?? app(ToolsProbe::class);
    }

    private function toolsFixer(): ToolsFixer
    {
        return $this->toolsFixer ?? app(ToolsFixer::class);
    }

    private function processRuntimeDrivers(): ProcessRuntimeDriverRegistry
    {
        return $this->processRuntimeDrivers ?? app(ProcessRuntimeDriverRegistry::class);
    }

    private function dockerSwarm(): RemoteDockerSwarmService
    {
        return $this->dockerSwarm ?? app(RemoteDockerSwarmService::class);
    }
}
