<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Metrics\MetricsRoleManager;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

final readonly class NativeMetricsRoleManager implements MetricsRoleManager
{
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
        $node = Node::query()->findOrFail($nodeId);
        $this->guardLinuxPlatform($node);
        app(DirectOperation::class)->writer('node.role_add');

        return app(AdmitNodeRoleOperationAction::class)->add($node, RoleName::Metrics, converge: true);
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
        app(DirectOperation::class)->writer('node.role_remove');
        $operation = app(AdmitNodeRoleOperationAction::class)->remove($node, RoleName::Metrics, $force, $purge);
        assert($operation instanceof Operation);

        return $operation;
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
