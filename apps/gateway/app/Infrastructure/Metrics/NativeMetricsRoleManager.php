<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Actions\Nodes\AddNodeRoleAction;
use App\Actions\Nodes\RemoveNodeRoleAction;
use App\Data\Metrics\MetricsMutationData;
use App\Domain\Metrics\MetricsPublicationReport;
use App\Domain\Metrics\MetricsRoleManager;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;

final readonly class NativeMetricsRoleManager implements MetricsRoleManager
{
    public function __construct(
        private AddNodeRoleAction $add,
        private RemoveNodeRoleAction $remove,
        private MetricsPublicationReport $report,
    ) {}

    public function enable(int $nodeId): MetricsMutationData
    {
        if (Node::query()->whereHas('roles', static fn ($query) => $query->where(
            'role',
            RoleName::Metrics->value,
        ))->exists()) {
            throw new RoleAssignmentException(
                'The metrics role is already assigned; remove it before enabling it on another node.',
            );
        }
        $node = Node::query()->findOrFail($nodeId);
        $this->guardLinuxPlatform($node);
        $result = $this->add->execute($node, RoleName::Metrics, true);

        return new MetricsMutationData($nodeId, $result['assignment']->status->value);
    }

    public function remove(bool $force, bool $purge): MetricsMutationData
    {
        $nodes = Node::query()
            ->whereHas('roles', static fn ($q) => $q->where('role', RoleName::Metrics->value))
            ->with('roles')
            ->limit(2)
            ->get();

        if ($nodes->isEmpty()) {
            throw new RoleAssignmentException('Metrics is not assigned.');
        }

        if ($nodes->count() !== 1) {
            throw new RoleAssignmentException('Metrics role assignment drift detected.');
        }

        $node = $nodes->sole();
        $this->remove->execute($node, RoleName::Metrics, $force, $purge);

        // The baseline records what it did to the publication. Reading the
        // Gateway state again here would report a guess, and the guess is
        // wrong whenever that state moved between the two reads.
        return new MetricsMutationData($node->id, 'removed', $this->report->take());
    }

    private function guardLinuxPlatform(Node $node): void
    {
        if ($node->platform === 'linux') {
            return;
        }

        throw new ResourceOperationException(
            'metrics.platform_unsupported',
            'Metrics exporters and the Metrics role require a Linux node.',
            422,
        );
    }
}
