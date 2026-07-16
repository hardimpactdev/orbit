<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Process;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Tools\ToolsFixer;
use App\Services\Tools\ToolsProbe;
use RuntimeException;

class RoleRuntimeConverger
{
    public function __construct(
        private readonly ?ToolsProbe $toolsProbe = null,
        private readonly ?ToolsFixer $toolsFixer = null,
        private readonly ?ProcessRuntimeDriverRegistry $processRuntimeDrivers = null,
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
}
