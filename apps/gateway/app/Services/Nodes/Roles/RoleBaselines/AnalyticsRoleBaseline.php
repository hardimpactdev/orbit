<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Services\Analytics\PlausibleRuntimeConfig;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Processes\ProcessServiceCatalog;
use App\Services\Tools\ToolCatalog;
use RuntimeException;

class AnalyticsRoleBaseline implements RoleBaseline
{
    use ManagesNodeToolBaseline;

    private const string ProcessName = 'plausible';

    private const string DefaultVersion = '3.2.1';

    public function __construct(
        private readonly ProcessServiceCatalog $serviceCatalog,
        private readonly PlausibleRuntimeConfig $plausibleRuntimeConfig,
        private readonly ?ToolCatalog $toolCatalog = null,
        private readonly ?NodeRoleAssignments $nodeRoleAssignments = null,
    ) {}

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        if ($this->nodeRoleAssignments()->nodeIsGateway($node)) {
            throw new RuntimeException('The analytics role cannot be assigned to a gateway node.');
        }

        if (! str_starts_with((string) $node->platform, 'ubuntu')) {
            throw new RuntimeException('The analytics role requires an Ubuntu host.');
        }

        $this->convergeTools($node, ['docker']);
        $this->convergePlausibleProcess($node, $assignment);
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        Process::query()
            ->ownedBy($node)
            ->where('name', self::ProcessName)
            ->withRuntimeService('plausible')
            ->delete();

        $this->removeTools($node, ['docker']);
    }

    protected function toolCatalog(): ToolCatalog
    {
        return $this->toolCatalog ?? app(ToolCatalog::class);
    }

    private function nodeRoleAssignments(): NodeRoleAssignments
    {
        return $this->nodeRoleAssignments ?? app(NodeRoleAssignments::class);
    }

    private function convergePlausibleProcess(Node $node, NodeRoleAssignment $assignment): void
    {
        $descriptor = $this->serviceCatalog->resolve(
            service: 'plausible',
            version: self::DefaultVersion,
            runtime: ProcessRuntime::DockerSwarm,
            node: $node,
            processName: self::ProcessName,
        );
        $existingProcess = Process::query()
            ->where('owner_type', $node->getMorphClass())
            ->where('owner_id', $node->id)
            ->where('name', self::ProcessName)
            ->first();

        if (! $existingProcess instanceof Process) {
            $existingProcess = null;
        }

        $runtimeConfig = $this->plausibleRuntimeConfig->for(
            assignment: $assignment,
            existingProcess: $existingProcess,
            runtimeConfig: $descriptor->runtimeConfig,
        );

        Process::query()->updateOrCreate(
            [
                'owner_type' => $node->getMorphClass(),
                'owner_id' => $node->id,
                'name' => self::ProcessName,
            ],
            [
                'node_id' => $node->id,
                'command' => $descriptor->command,
                'restart_policy' => ProcessRestartPolicy::Always,
                'crash_notification' => ProcessCrashNotification::AgentIde,
                'runtime' => ProcessRuntime::DockerSwarm,
                'tool' => null,
                'runtime_config' => $runtimeConfig,
                'sort_order' => 10,
            ],
        );
    }
}
