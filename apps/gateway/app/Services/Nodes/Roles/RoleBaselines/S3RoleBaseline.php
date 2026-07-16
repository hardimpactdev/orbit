<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Services\S3\S3ServiceConfigurator;
use App\Services\Tools\ToolCatalog;

class S3RoleBaseline implements RoleBaseline
{
    use ManagesNodeToolBaseline;

    public function __construct(
        private readonly S3ServiceConfigurator $configurator,
        private readonly ?ToolCatalog $toolCatalog = null,
        private readonly ?RoleRuntimeConverger $runtimeConverger = null,
    ) {}

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        $this->convergeTools($node, ['docker']);
        $this->runtimeConverger()->convergeTool($node, 'docker');
        $result = $this->configurator->configure($node, $assignment);
        $this->runtimeConverger()->convergeProcess($node, $result->process, 's3');
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        /** @var Process|null $process */
        $process = Process::query()
            ->where('node_id', $node->id)
            ->where('tool', 'seaweedfs')
            ->first();

        if ($process instanceof Process) {
            $this->runtimeConverger()->removeProcess($node, $process, 's3');
        }

        $this->configurator->remove($node, $purgeData);
    }

    protected function toolCatalog(): ToolCatalog
    {
        return $this->toolCatalog ?? app(ToolCatalog::class);
    }

    private function runtimeConverger(): RoleRuntimeConverger
    {
        return $this->runtimeConverger ?? app(RoleRuntimeConverger::class);
    }
}
