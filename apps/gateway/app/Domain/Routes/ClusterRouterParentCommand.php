<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\AppDev\PrivateDnsAnswerExpiry;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Carbon;

/** Cluster state and proof only. Baselines, sites, certificates and DNS are child Operations. */
final readonly class ClusterRouterParentCommand implements OperationGatewayCommand
{
    public function __construct(private RouteProjectionPlan $plans, private ClusterRouterParentExecution $execution,
        private OperationChildren $children, private PrivateDnsAnswerExpiry $expiry) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['action'],
            'properties' => ['action' => ['enum' => ['prepare', 'open', 'commit', 'prove']],
                'stage' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80]]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        if (! in_array($operation->kind, ClusterRouterParentExecution::Kinds, true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This is not a Cluster Router parent.', 422);
        }
        $operation->refresh();
        if ($input['action'] === 'prepare') {
            if (! is_array($operation->state['route_plan'] ?? null)) {
                $operation->update(['state' => [...($operation->state ?? []), 'route_plan' => $this->plans->clusterRouter($operation)]]);
            }
            $plan = ValidatedData::object($operation->state['route_plan'] ?? []);
            $phases = [];
            foreach (ValidatedData::object($plan['stages']) as $stage => $children) {
                foreach (RouteExecutionProof::objects($children) as $child) {
                    $phases[] = ['stage' => $stage, 'key' => $child['key']];
                }
            }

            return ['prepared' => true, 'mode' => $plan['mode'], 'production' => false, 'public' => false,
                'remote_router' => false, 'web_root' => false, 'targeted' => false, 'child_phases' => $phases];
        }
        $plan = ValidatedData::object($operation->state['route_plan'] ?? []);
        $completed = ValidatedData::object($operation->state['route_completed_stages'] ?? []);
        if ($input['action'] === 'prove') {
            foreach ($this->execution->sequence($operation) as $phase) {
                if (($completed[$phase] ?? false) !== true) {
                    throw new ResourceOperationException('operation.completion_unproven', 'Finish every required Router phase first.', 409);
                }
                foreach (RouteExecutionProof::objects(ValidatedData::object($plan['stages'])[$phase] ?? []) as $child) {
                    $this->children->requireSucceeded($operation, ValidatedData::string($child['key']));
                }
            }

            return ['ready' => true, 'cluster_id' => $plan['cluster_id']];
        }
        $stage = ValidatedData::string($input['stage']);
        if ($input['action'] === 'open' && $stage === 'restore-dns' && ! isset($operation->state['route_branch'])) {
            if (($completed['dns-publication'] ?? false) === true) {
                throw new ResourceOperationException('operation.completion_unproven', 'A published Router replacement recovers forward.', 409);
            }
            $failed = false;
            $code = 'cluster.router_failed';
            foreach ($this->execution->childContext($operation)['children'] as $intent) {
                $child = $this->children->child($operation, $intent['key']);
                if (in_array($child->status->value, ['failed', 'cancelled'], true)) {
                    $failed = true;
                    $code = $child->error_code ?? $code;
                }
            }
            if (! $failed) {
                throw new ResourceOperationException('operation.child_unproven', 'Restoring a Router requires its own failed child.', 409);
            }
            NodeRole::query()->whereKey($plan['candidate_role_id'])->update(['status' => LifecycleStatus::Provisioning,
                'failed_step' => 'rollback:dns-publication', 'error_code' => $code]);
            $operation->update(['state' => [...($operation->state ?? []), 'route_branch' => 'rollback_router', 'route_failure_code' => $code]]);
        }
        $next = array_find($this->execution->sequence($operation), static fn (string $phase): bool => ($completed[$phase] ?? false) !== true);
        if ($next !== $stage) {
            if (($completed[$stage] ?? false) === true) {
                return $input['action'] === 'open' ? ['opened' => true, 'wait_seconds' => 0] : ['ready' => true, 'stage' => $stage];
            }
            throw new ResourceOperationException('operation.completion_unproven', 'The preceding Router phase has no proof.', 409);
        }
        $remaining = $this->remaining($operation, $stage);
        if ($input['action'] === 'open') {
            if ($stage === 'withdraw-assignments') {
                NodeRole::query()->whereKey($plan['assignment_ids'])->update(['status' => LifecycleStatus::Removing, 'failed_step' => ClusterRouterTransition::OldRouterCleanup]);
            } elseif ($stage === 'dns-publication' && $plan['mode'] === 'router_set') {
                // Both Nodes already serve. Persist DNS selection before publishing on any resolver.
                NodeRole::query()->whereKey($plan['candidate_role_id'])->update(['failed_step' => ClusterRouterReplacementStep::DnsPublished->value, 'error_code' => null]);
            } elseif ($stage === 'withdraw-old-sites') {
                NodeRole::query()->whereKey($plan['old_role_ids'])->update(['failed_step' => ClusterRouterTransition::OldRouterCleanup]);
            } elseif ($stage === 'withdraw-candidate-sites') {
                NodeRole::query()->whereKey($plan['candidate_role_id'])->update(['status' => LifecycleStatus::Failed]);
            }
            $operation->update(['state' => [...($operation->state ?? []), 'route_active_stage' => $stage]]);

            return ['opened' => true, 'wait_seconds' => $remaining];
        }
        if (($operation->state['route_active_stage'] ?? null) !== $stage || $remaining > 0) {
            throw new ResourceOperationException('operation.completion_unproven', 'Open the Router phase and preserve the DNS grace period before committing it.', 409);
        }
        foreach ($this->execution->childContext($operation)['children'] as $child) {
            $this->children->requireSucceeded($operation, $child['key']);
        }
        $candidate = isset($plan['candidate_role_id']) ? NodeRole::query()->find(ValidatedData::integer($plan['candidate_role_id'])) : null;
        if ($stage === 'database') {
            NodeRole::query()->whereKey($plan['old_role_ids'])->update(['status' => LifecycleStatus::Removing, 'failed_step' => null]);
            $candidate?->update(['status' => LifecycleStatus::Active, 'failed_step' => ClusterRouterReplacementStep::DatabaseCutover->value, 'error_code' => null]);
        } elseif ($stage === 'prune-dns' || $stage === 'restore-dns') {
            $operation->update(['state' => [...($operation->state ?? []), 'router_dns_moved_at' => now()->toIso8601String()]]);
        } elseif ($stage === 'complete-router') {
            NodeRole::query()->whereKey($plan['old_role_ids'])->delete();
            $candidate?->update(['failed_step' => null, 'error_code' => null]);
        } else {
            $checkpoint = ClusterRouterReplacementStep::tryFrom($stage);
            if ($checkpoint !== null && $candidate !== null) {
                $candidate->update(['failed_step' => $checkpoint->value, 'error_code' => null]);
            }
        }
        $operation->update(['state' => [...($operation->state ?? []), 'route_active_stage' => null,
            'route_completed_stages' => [...$completed, $stage => true]]]);

        return ['ready' => true, 'stage' => $stage];
    }

    private function remaining(Operation $operation, string $stage): int
    {
        if (! in_array($stage, ['await-old-dns-expiry', 'await-restored-dns-expiry'], true)) {
            return 0;
        }
        $movedAt = ValidatedData::nullableString($operation->state['router_dns_moved_at'] ?? null);

        return $this->expiry->remainingAfter($movedAt === null ? null : Carbon::parse($movedAt));
    }
}
