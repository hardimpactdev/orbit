<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Actions\Metrics\StartMetricsOperationAction;
use App\Actions\Nodes\AssignRoleAction;
use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\MetricsRoleManager;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodeRoleDependencyInspector;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

final readonly class NativeMetricsRoleManager implements MetricsRoleManager
{
    public function __construct(
        private AssignRoleAction $assign,
        private StartMetricsOperationAction $operations,
        private ExporterPreferenceRepository $preferences,
        private ManagedNodeEligibility $eligibility,
        private NodeRoleDependencyInspector $dependencies,
    ) {}

    public function enable(int $nodeId): Operation
    {
        if (Node::query()->whereHas('roles', static fn ($query) => $query->where(
            'role',
            RoleName::Metrics->value,
        ))->exists()) {
            throw new RoleAssignmentException(
                'The metrics role is already assigned; remove it before enabling it on another node.',
            );
        }
        $node = $this->activeNode($nodeId);
        $this->guardLinuxPlatform($node);

        return DB::transaction(function () use ($node): Operation {
            $assignment = $this->assign->execute($node, RoleName::Metrics);

            return $this->operations->execute($node, $assignment, 'enable');
        });
    }

    public function remove(bool $force, bool $purge): Operation
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
        $preview = $this->dependencies->inspect($node, RoleName::Metrics);
        if (! $force) {
            throw new NodeRoleValidationException('Use --force to remove this node role.', ['field' => 'force', 'reason' => 'destructive_consent_required', 'role' => 'metrics', 'dependents' => $preview->summaries]);
        }

        return DB::transaction(function () use ($node, $purge): Operation {
            $assignment = $node->roles()->where('role', RoleName::Metrics)->sole();
            $assignment->update(['status' => LifecycleStatus::Removing]);

            return $this->operations->execute($node, $assignment, 'disable', $purge);
        });
    }

    public function enableExporter(int $nodeId): Operation
    {
        $node = $this->activeNode($nodeId);
        $this->guardLinuxPlatform($node);

        if (! $this->eligibility->allows($node)) {
            throw new ResourceOperationException(
                'metrics.exporter_node_ineligible',
                'Metrics exporters require an active managed Linux node with WireGuard and Gateway SSH management.',
                409,
            );
        }

        $this->preferences->put($nodeId, ExporterPreference::Enabled);

        return $this->reconcile('exporter_enable', $node);
    }

    public function disableExporter(int $nodeId): Operation
    {
        $node = $this->activeNode($nodeId);
        $this->guardLinuxPlatform($node);
        if ($node->roles()->where('role', RoleName::Metrics->value)->exists()) {
            throw new RoleAssignmentException('The metrics node exporter cannot be disabled.');
        }
        $this->preferences->put($nodeId, ExporterPreference::Disabled);

        return $this->reconcile('exporter_disable', $node);
    }

    private function reconcile(string $kind, Node $changed): Operation
    {
        $assignments = NodeRole::query()->where('role', RoleName::Metrics)->where('status', LifecycleStatus::Active)->with('node')->limit(2)->get();
        if ($assignments->isEmpty()) {
            return $this->operations->preference($changed, $kind);
        }
        if ($assignments->count() !== 1) {
            throw new ResourceOperationException('metrics.assignment_missing', 'Exactly one active Metrics assignment is required.', 409);
        }
        $assignment = $assignments->sole();

        return $this->operations->execute($assignment->node, $assignment, $kind);
    }

    private function activeNode(int $nodeId): Node
    {
        $node = Node::query()->findOrFail($nodeId);

        if ($node->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException(
                'metrics.exporter_node_inactive',
                'Metrics exporters require an active node.',
                409,
            );
        }

        return $node;
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
