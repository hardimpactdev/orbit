<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Routes\RouteMutationState;
use App\Actions\Routes\RouteRemovalState;
use App\Actions\Routes\RouteTargetState;
use App\Actions\Routes\RouteWebRootState;
use App\Data\Routes\RouteTargetDispositionData;
use App\Data\Routes\SetRouteTargetsData;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Operation;
use App\Models\Route;
use App\Models\RouteRemovalResidue;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records a Route plan and projects only child-proven state. No Node execution. */
final readonly class RouteParentCommand implements OperationGatewayCommand
{
    public function __construct(private RouteProjectionPlan $plans, private RouteParentExecution $execution,
        private RouteMutationState $mutations, private RouteRemovalState $removal, private RouteOfflineSelection $offline) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['action'],
            'properties' => ['action' => ['enum' => ['prepare', 'open', 'commit', 'prove']], 'stage' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80]]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        return DB::transaction(fn (): array => $this->runOwned($operation, $input));
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function runOwned(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        if (! in_array($operation->kind, RouteParentExecution::Kinds, true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This is not an owned Route parent.', 422);
        }
        $operation->refresh();
        if ($input['action'] === 'prepare') {
            return $this->prepare($operation);
        }
        $plan = ValidatedData::object($operation->state['route_plan'] ?? []);
        $completed = ValidatedData::object($operation->state['route_completed_stages'] ?? []);
        if ($input['action'] === 'prove') {
            foreach ($this->execution->sequence($operation) as $stage) {
                if (($completed[$stage] ?? false) !== true) {
                    throw new ResourceOperationException('operation.completion_unproven', 'Finish every required Route phase first.', 409);
                }
                foreach (RouteExecutionProof::objects(ValidatedData::object($plan['stages'])[$stage] ?? []) as $child) {
                    app(OperationChildren::class)->requireSucceeded($operation, ValidatedData::string($child['key']));
                }
            }

            return ['ready' => true, 'route_id' => $plan['result_route_id'] ?? $plan['route_id'], 'retained_on_nodes' => $plan['retained_on_nodes'] ?? []];
        }
        $stage = ValidatedData::string($input['stage']);
        if ($input['action'] === 'open' && (str_starts_with($stage, 'rollback-') || $stage === 'synchronize-web-root-urls.rollback') && ! isset($operation->state['route_branch']) && ($plan['mode'] ?? null) !== 'withdraw_replacement') {
            $this->beginRollback($operation, $stage, $plan, $completed);
        }
        $next = array_find($this->execution->sequence($operation), static fn (string $candidate): bool => ($completed[$candidate] ?? false) !== true);
        if ($next !== $stage) {
            if (($completed[$stage] ?? false) === true) {
                return $input['action'] === 'open' ? ['opened' => true] : ['ready' => true, 'stage' => $stage];
            }
            throw new ResourceOperationException('operation.completion_unproven', 'The preceding Route phase has no proof.', 409);
        }
        if ($input['action'] === 'open') {
            $this->open($operation, $stage, $plan);
            $operation->update(['state' => [...($operation->state ?? []), 'route_active_stage' => $stage]]);

            return ['opened' => true];
        }
        if (($operation->state['route_active_stage'] ?? null) !== $stage) {
            throw new ResourceOperationException('operation.completion_unproven', 'Open the recorded Route phase before committing it.', 409);
        }
        foreach ($this->execution->childContext($operation)['children'] as $child) {
            app(OperationChildren::class)->requireSucceeded($operation, $child['key']);
        }
        $this->commit($operation, $stage, $plan);
        $operation->update(['state' => [...($operation->state ?? []), 'route_active_stage' => null, 'route_completed_stages' => [...$completed, $stage => true]]]);

        return ['ready' => true, 'stage' => $stage];
    }

    /** @return array<string, mixed> */
    private function prepare(Operation $operation): array
    {
        if (! is_array($operation->state['route_plan'] ?? null)) {
            $route = Route::query()->findOrFail(ValidatedData::integer($operation->input['route_id']));
            $mode = $operation->input['mode'] ?? null;
            if ($operation->kind === 'route.retire_source') {
                $plan = $this->plans->retireSource($operation, InstanceTransfer::query()->findOrFail(ValidatedData::string($operation->input['transfer_id'])));
                $mode = 'retire_source';
            } elseif (in_array($operation->kind, ['route.withdraw', 'route.destroy'], true)) {
                if ($route->isUnactivatedReplacement()) {
                    $plan = $this->plans->replacement($operation, Route::query()->findOrFail($route->replaces_route_id), $route);
                    $mode = 'withdraw_replacement';
                    $plan['result_route_id'] = $route->id;
                    $plan['residue_route_id'] = $route->id;
                    $plan['domain'] = $route->domain;
                } else {
                    $plan = $this->plans->withdraw($operation, $route);
                    $mode = 'withdraw';
                }
            } elseif ($mode === 'replace_domain' || $mode === 'replace_pending') {
                $candidate = Route::query()->findOrFail(ValidatedData::integer($operation->input['candidate_route_id']));
                $plan = $this->plans->replacement($operation, $route, $candidate);
                $mode = $mode === 'replace_pending' ? 'replace_pending' : 'replacement';
                if ($route->status === RouteStatus::Retiring && in_array($candidate->status, [RouteStatus::Activating, RouteStatus::Active], true)) {
                    $mode = $candidate->replacement_step === RouteReplacementStep::Cleanup ? 'replacement_cleanup' : 'replacement_after_cutover';
                }
                $plan['result_route_id'] = $candidate->id;
            } elseif ($mode === 'targets_recover') {
                $source = app(RouteTargetState::class)->recovery($route);
                if ($source->id !== ($operation->input['recovery_operation_id'] ?? null)) {
                    throw new ResourceOperationException('route.target_set_conflict', 'The recovery Operation changed.', 409);
                }
                app(RouteTargetState::class)->assertCommitted($route, $this->proposal($operation));
                $plan = ValidatedData::object($source->state['route_plan'] ?? []);
                $stages = ValidatedData::object($plan['stages']);
                $stages['removal'] = array_values(array_filter(RouteExecutionProof::objects($stages['removal'] ?? []), static fn (array $child): bool => Instance::query()->whereKey(ValidatedData::integer(ValidatedData::object($child['input'])['instance_id']))->exists()));
                $plan['stages'] = $stages;
            } elseif ($mode === 'targets') {
                $plan = $this->plans->targets($operation, $route, $this->proposal($operation));
                $route->update(['target_set_intent' => [...($route->target_set_intent ?? []), 'preparation_operation_id' => $operation->id]]);
            } elseif ($mode === 'publication') {
                $plan = $this->plans->publication($operation, $route);
            } elseif ($mode === 'web_root') {
                app(RouteWebRootState::class)->assertChange($route, ValidatedData::nullableString($operation->input['web_root']));
                $plan = $this->plans->webRoot($operation, $route);
            } elseif ($mode === 'state_only') {
                $plan = ['route_id' => $route->id, 'publication' => $route->publication->value, 'placements' => [], 'stages' => []];
            } elseif (in_array($operation->kind, ['route.create', 'route.converge'], true) || $mode === 'target') {
                $instanceId = $operation->input['instance_id'] ?? null;
                $plan = $this->plans->converge($operation, $route, is_int($instanceId) ? Instance::query()->findOrFail($instanceId) : null);
                $mode = 'converge';
            } else {
                throw new ResourceOperationException('operation.kind_unavailable', 'This Route mutation plan needs its remaining family contract.', 409);
            }
            if (($operation->input['offline'] ?? false) === true && in_array($mode, ['withdraw', 'withdraw_replacement'], true)) {
                $plan = $this->offline->apply($plan);
            }
            $plan['metrics'] = in_array($operation->kind, ['route.update', 'route.destroy', 'route.target_unset'], true)
                || ($operation->kind === 'route.target_set' && ($operation->input['mode'] ?? null) === 'target');
            if ($plan['metrics']) {
                $plan = $this->plans->withMetrics($plan);
            }
            $placements = RouteExecutionProof::objects($plan['placements'] ?? []);
            $plan['production'] = $placements !== [] && array_all($placements, static fn (array $placement): bool => ($placement['production'] ?? false) === true);
            $plan['web_root'] ??= $route->hasWebRoot();
            $plan['remote_router'] = isset(ValidatedData::object($plan['stages'])['router-caddy']);
            $plan['targeted'] = $route->targets()->exists();
            $plan['mode'] = $mode;
            $nodes = [];
            foreach (ValidatedData::object($plan['stages']) as $phase => $children) {
                foreach (RouteExecutionProof::objects($children) as $child) {
                    $nodes[] = ValidatedData::integer($child['node_id']);
                }
            }
            $plan['node_ids'] = array_values(array_unique($nodes));
            $operation->update(['state' => [...($operation->state ?? []), 'route_plan' => $plan]]);
        }
        $plan = ValidatedData::object($operation->state['route_plan'] ?? []);
        $phases = [];
        foreach (ValidatedData::object($plan['stages']) as $phase => $children) {
            foreach (RouteExecutionProof::objects($children) as $child) {
                $phases[] = ['stage' => $phase, 'key' => ValidatedData::string($child['key'])];
            }
        }

        return ['prepared' => true, 'mode' => $plan['mode'], 'production' => $plan['production'], 'public' => $plan['publication'] === 'public',
            'remote_router' => $plan['remote_router'], 'web_root' => $plan['web_root'], 'targeted' => $plan['targeted'], 'child_phases' => $phases, 'activate_public' => $plan['activate_public'] ?? false, 'withdraw_public' => $plan['withdraw_public'] ?? false,
            'changed' => $plan['changed'] ?? true, 'metrics' => $plan['metrics'] ?? false];
    }

    /** @param array<string, mixed> $plan */
    private function open(Operation $operation, string $stage, array $plan): void
    {
        $route = Route::query()->find(ValidatedData::integer($plan['candidate_route_id'] ?? $plan['route_id']));
        if ($route === null) {
            return;
        }
        if ($plan['mode'] === 'withdraw_replacement') {
            $route->update(['status' => RouteStatus::Failed, 'sites_published' => false,
                'failed_step' => $route->failed_step ?? 'withdrawal', 'error_code' => $route->error_code ?? 'route.replacement_withdrawn']);
        }
        if ($stage === 'dns' && $plan['mode'] === 'withdraw') {
            $this->removal->beginRemoval($route);
        } elseif ($stage === 'web-root-stored' || $stage === 'rollback-web-root-stored') {
            $route->update(['web_root' => $stage === 'web-root-stored' ? $plan['requested_web_root'] : $plan['previous_web_root']]);
        } elseif ($stage === 'publication-stored') {
            $route->update(['publication' => $plan['publication'], 'replacement_step' => null, 'failed_step' => null, 'error_code' => null]);
        } elseif ($stage === 'cleanup-caddy') {
            $this->mutations->checkpoint($route, RouteReplacementStep::Cleanup);
        } elseif ($stage === 'public-activated') {
            $route->update(['status' => RouteStatus::Activating, 'replacement_step' => RouteReplacementStep::PublicActivated]);
        }
    }

    /** @param array<string, mixed> $plan */
    private function commit(Operation $operation, string $stage, array $plan): void
    {
        $route = Route::query()->find(ValidatedData::integer($plan['route_id']));
        $candidate = isset($plan['candidate_route_id']) ? Route::query()->find(ValidatedData::integer($plan['candidate_route_id'])) : null;
        $current = $candidate ?? $route;
        if ($stage === 'publish-sites') {
            $current?->publishSites();
        } elseif ($stage === 'activate-route') {
            $current?->update(['status' => RouteStatus::Active, 'failed_step' => null, 'error_code' => null]);
        } elseif ($stage === 'publication-completed' && $route !== null && ($plan['activate_public'] ?? false)) {
            $route->update(['status' => RouteStatus::Active, 'replacement_step' => RouteReplacementStep::IngressFirewall]);
        } elseif ($stage === 'database-committed' && $route !== null) {
            if ($plan['mode'] === 'targets_recover') {
                app(RouteTargetState::class)->assertCommitted($route, $this->proposal($operation));
            } else {
                app(RouteTargetState::class)->commitAssociations($route, $this->proposal($operation));
            }
            $route->refresh();
            $route->update(['target_set_intent' => [...($route->target_set_intent ?? []), 'vacated_route_ids' => $plan['vacated_route_ids'], 'preparation_operation_id' => $operation->id]]);
        } elseif ($stage === 'completed' && in_array($plan['mode'], ['targets', 'targets_recover'], true) && $route !== null) {
            app(RouteTargetState::class)->complete($route);
        } elseif ($stage === 'database-cutover' && $route !== null && $candidate !== null) {
            $this->mutations->cutover($route, $candidate);
        } elseif ($stage === 'complete-replacement' || $stage === 'replace-pending') {
            if ($route !== null && $candidate !== null) {
                $route->update(['replaced_by_route_id' => null]);
                $candidate->update(['replaces_route_id' => null, 'replacement_step' => null, 'status' => $stage === 'replace-pending' ? RouteStatus::Pending : RouteStatus::Active,
                    'failed_step' => null, 'error_code' => null]);
                $route->delete();
            }
        } elseif ($stage === 'rollback-record') {
            if ($route !== null && $candidate !== null) {
                if ($operation->kind !== 'route.withdraw') {
                    $route->update(['replaced_by_route_id' => null]);
                    $candidate->delete();
                }
            }
            $this->recordResidue($plan);
        } elseif ($stage === 'record') {
            if ($operation->kind === 'route.destroy') {
                $route?->delete();
            }
            $this->recordResidue($plan);
        } elseif (in_array($plan['mode'], ['replacement', 'replacement_after_cutover', 'replacement_cleanup'], true) && $current !== null && ! str_starts_with($stage, 'rollback-')) {
            $step = RouteReplacementStep::tryFrom($stage);
            if ($step !== null) {
                $this->mutations->checkpoint($current, $step);
            }
        }
        if (in_array($plan['mode'], ['targets', 'targets_recover'], true) && $route !== null && $stage !== 'completed') {
            $step = $stage === 'target-workload-verified' ? RouteTargetSetStep::WorkloadPrepared : RouteTargetSetStep::tryFrom($stage);
            if ($step !== null) {
                app(RouteTargetState::class)->advance($route, $step);
            }
        }
    }

    private function proposal(Operation $operation): SetRouteTargetsData
    {
        return new SetRouteTargetsData(ValidatedData::integerList($operation->input['targets']),
            array_map(static fn (array $value): RouteTargetDispositionData => new RouteTargetDispositionData(ValidatedData::integer($value['instance_id']),
                isset($value['route_id']) ? ValidatedData::integer($value['route_id']) : null, ($value['remove'] ?? false) === true),
                RouteExecutionProof::objects($operation->input['dispositions'] ?? [])));
    }

    /** @param array<string, mixed> $plan */
    private function recordResidue(array $plan): void
    {
        foreach (RouteExecutionProof::objects($plan['retained_on_nodes'] ?? []) as $retained) {
            RouteRemovalResidue::query()->updateOrCreate(
                ['node_id' => $retained['node_id'], 'route_id' => $plan['residue_route_id'] ?? $plan['route_id']],
                ['domain' => $plan['domain'], 'steps' => $retained['steps']],
            );
        }
    }

    /** @param array<string, mixed> $plan
     * @param  array<string, mixed>  $completed
     */
    private function beginRollback(Operation $operation, string $stage, array $plan, array $completed): void
    {
        $active = ValidatedData::nullableString($operation->state['route_active_stage'] ?? null);
        $failed = false;
        $failureCode = 'route.child_failed';
        foreach ($this->execution->childContext($operation)['children'] as $intent) {
            $child = app(OperationChildren::class)->child($operation, $intent['key']);
            if (in_array($child->status->value, ['failed', 'cancelled'], true)) {
                $failed = true;
                $failureCode = $child->error_code ?? 'route.child_failed';
            }
        }
        $cutover = ($completed['database-cutover'] ?? false) === true || in_array($plan['mode'], ['replacement_after_cutover', 'replacement_cleanup'], true);
        if (! in_array($plan['mode'], ['replacement', 'replacement_after_cutover', 'replacement_cleanup', 'web_root', 'publication', 'targets'], true) || ! $failed || ($cutover && $stage !== 'rollback-public-edge')) {
            throw new ResourceOperationException('operation.completion_unproven', 'A Route rollback requires its own proven failed child before cutover.', 409);
        }
        if ($plan['mode'] === 'targets') {
            if (($completed['database-committed'] ?? false) === true) {
                throw new ResourceOperationException('operation.completion_unproven', 'A committed target-set change recovers forward.', 409);
            }
            $operation->update(['state' => [...($operation->state ?? []), 'route_branch' => 'rollback_targets', 'route_failure_code' => $failureCode]]);

            return;
        }
        if ($plan['mode'] === 'web_root') {
            $operation->update(['state' => [...($operation->state ?? []), 'route_branch' => 'rollback_web_root', 'route_failure_code' => $failureCode]]);

            return;
        }
        if ($plan['mode'] === 'publication') {
            Route::query()->whereKey($plan['route_id'])->update(['replacement_step' => RouteReplacementStep::PublicEdgeVerified]);
            $operation->update(['state' => [...($operation->state ?? []), 'route_branch' => 'rollback_publication', 'route_failure_code' => $failureCode]]);

            return;
        }
        $candidate = Route::query()->findOrFail(ValidatedData::integer($plan['candidate_route_id']));
        $candidate->update($cutover ? ['replacement_step' => RouteReplacementStep::DatabaseCutover] : ['status' => RouteStatus::Failed, 'sites_published' => false, 'failed_step' => $active ?? 'withdrawal', 'error_code' => $failureCode]);
        $operation->update(['state' => [...($operation->state ?? []), 'route_branch' => $cutover ? 'rollback_public_edge' : 'rollback', 'route_failure_code' => $failureCode]]);
    }
}
