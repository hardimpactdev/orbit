<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\LifecycleStatus;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class ClusterRouterParentExecution extends RoutePhaseExecution
{
    public const array Kinds = ['cluster.router_set', 'cluster.router_unset'];

    protected function command(): string
    {
        return 'route.cluster-router';
    }

    public function sequence(Operation $operation): array
    {
        $plan = ValidatedData::object($operation->state['route_plan'] ?? []);
        if (($operation->state['route_branch'] ?? null) === 'rollback_router') {
            return ['restore-dns', 'await-restored-dns-expiry', 'withdraw-candidate-sites', 'remove-candidate-certificates', 'remove-candidate-firewall'];
        }

        return match ($plan['mode'] ?? null) {
            'router_noop' => ['prune-dns', 'complete-router'],
            'router_unset' => ['withdraw-assignments', 'dns-publication', 'remove-old-baseline', 'complete-router'],
            default => ['baseline', 'router-certificate', 'firewall-policy', 'workload-verify', 'router-caddy', 'database',
                'dns-publication', 'prune-dns', 'await-old-dns-expiry', 'withdraw-old-sites', 'remove-old-certificates',
                'remove-old-firewall', 'remove-old-baseline', 'complete-router'],
        };
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        parent::assertCompletion($operation, $outcome);
        if ($outcome->status->value !== 'failed') {
            return;
        }
        $id = $operation->input['candidate_role_id'] ?? null;
        $candidate = is_int($id) ? NodeRole::query()->find($id) : null;
        if ($candidate !== null) {
            $candidate->update(['status' => $candidate->status === LifecycleStatus::Active ? LifecycleStatus::Active : LifecycleStatus::Failed,
                'error_code' => $outcome->errorCode ?? 'cluster.router_failed']);
        }
    }
}
