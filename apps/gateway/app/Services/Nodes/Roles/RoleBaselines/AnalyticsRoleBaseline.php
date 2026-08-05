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
        private readonly ?RoleRuntimeConverger $runtimeConverger = null,
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
        $this->runtimeConverger()->convergeTool($node, 'docker');
        $this->runtimeConverger()->convergeProcess(
            $node,
            $this->convergePlausibleProcess($node, $assignment),
            'analytics',
        );
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        /** @var Process|null $process */
        $process = Process::query()
            ->ownedBy($node)
            ->where('name', self::ProcessName)
            ->withRuntimeService('plausible')
            ->first();

        if ($process instanceof Process) {
            $this->runtimeConverger()->removeProcess($node, $process, 'analytics');
            $process->delete();
        }
    }

    protected function toolCatalog(): ToolCatalog
    {
        return $this->toolCatalog ?? app(ToolCatalog::class);
    }

    private function nodeRoleAssignments(): NodeRoleAssignments
    {
        return $this->nodeRoleAssignments ?? app(NodeRoleAssignments::class);
    }

    private function convergePlausibleProcess(Node $node, NodeRoleAssignment $assignment): Process
    {
        $descriptor = $this->serviceCatalog->resolve(
            service: 'plausible',
            version: self::DefaultVersion,
            runtime: ProcessRuntime::Docker,
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

        $settings = $this->plausibleRuntimeConfig->for(
            assignment: $assignment,
            existingProcess: $existingProcess,
            runtimeConfig: $descriptor->runtimeConfig,
        );

        /** @var Process */
        return Process::query()->updateOrCreate(
            [
                'owner_type' => $node->getMorphClass(),
                'owner_id' => $node->id,
                'name' => self::ProcessName,
            ],
            [
                'node_id' => $node->id,
                'command' => $descriptor->command,
                'restart_policy' => ProcessRestartPolicy::Always,
                'crash_notification' => ProcessCrashNotification::None,
                'runtime' => ProcessRuntime::Docker,
                'tool' => null,
                'runtime_config' => $settings->runtimeConfig,
                'credentials' => $settings->credentials,
                'sort_order' => 10,
            ],
        );
    }

    private function runtimeConverger(): RoleRuntimeConverger
    {
        return $this->runtimeConverger ?? app(RoleRuntimeConverger::class);
    }
}
