<?php

declare(strict_types=1);

namespace App\Actions\Metrics;

use App\Actions\Nodes\AssignRoleAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsRoleInput;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Routes\NativeRoutePublicationInput;
use App\Domain\Routes\PrivateDnsPublicationNodes;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

final readonly class AdmitMetricsRoleAction
{
    public function execute(?int $nodeId, bool $enabled, bool $force = false, bool $purge = false): Operation
    {
        $kind = $enabled ? 'metrics.enable' : 'metrics.disable';
        if (($existing = app(OperationIdempotency::class)->replay($kind)) !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($nodeId, $enabled, $force, $purge, $kind): Operation {
            Node::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            if (($existing = app(OperationIdempotency::class)->replay($kind)) !== null) {
                return $existing;
            }
            $assignments = NodeRole::query()->where('role', RoleName::Metrics)->with('node')->get();
            if (($enabled && $assignments->isNotEmpty()) || (! $enabled && $assignments->count() !== 1)) {
                throw new RoleAssignmentException($enabled ? 'The metrics role is already assigned; remove it before enabling it on another node.' : 'Metrics removal requires one assignment.');
            }
            $node = $enabled ? Node::query()->findOrFail($nodeId) : $assignments->sole()->node;
            Node::query()->whereKey($node->id)->lockForUpdate()->firstOrFail();
            if ($node->platform !== 'linux') {
                throw new ResourceOperationException('metrics.platform_unsupported', 'Metrics requires Linux.', 422);
            }
            if ($node->status !== LifecycleStatus::Active || ! app(ManagedNodeEligibility::class)->allows($node)) {
                throw new ResourceOperationException('metrics.node_ineligible', 'Metrics requires an active managed SSH Node.', 409);
            }
            if (! $enabled && ! $force) {
                throw new ResourceOperationException('metrics.force_required', 'Metrics removal requires force.', 422);
            }
            $before = [];
            foreach (PrivateDnsPublicationNodes::query()->get() as $dnsNode) {
                $before[$dnsNode->id] = app(NativeRoutePublicationInput::class)->dns($dnsNode);
            }
            $assignment = $enabled ? app(AssignRoleAction::class)->execute($node, RoleName::Metrics) : $assignments->sole();
            if ($enabled) {
                app(MetricsCredentialManager::class)->passwordForConvergence($node);
            } else {
                $assignment->update(['status' => LifecycleStatus::Removing, 'failed_step' => null, 'error_code' => null]);
            }
            $intent = app(MetricsRoleInput::class)->capture($assignment, $enabled, $purge, $before);

            return app(StartOperationAction::class)->execute($kind, 'metrics', $node->id, $node->id, $intent, 'operator', request()->attributes->getString('orbit.request_id'));
        });
    }
}
