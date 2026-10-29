<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Routes\RouteRemovalState;
use App\Domain\AppDev\PrivateDnsAnswerExpiry;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** DB-only state transitions, checked against B's canonical child proof. */
final readonly class AnalyticsRouteParentCommand implements OperationGatewayCommand
{
    public function __construct(private RouteProjectionPlan $plans, private AnalyticsRouteParentExecution $execution,
        private RouteRemovalState $removal, private PrivateDnsAnswerExpiry $expiry) {}

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
        if (! in_array($operation->kind, AnalyticsRouteParentExecution::Kinds, true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This is not an analytics Route parent.', 422);
        }
        $operation->refresh();
        $route = Route::query()->find(ValidatedData::integer($operation->input['route_id']));
        if ($input['action'] === 'prepare') {
            if (! is_array($operation->state['route_plan'] ?? null)) {
                $route ??= Route::query()->findOrFail(ValidatedData::integer($operation->input['route_id']));
                $plan = $this->plans->analytics($operation, $route);
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
            RouteKitAvailability::plan($operation, $plan);
            $phases = [];
            foreach (ValidatedData::object($plan['stages']) as $phase => $children) {
                foreach (RouteExecutionProof::objects($children) as $child) {
                    $phases[] = ['stage' => $phase, 'key' => ValidatedData::string($child['key'])];
                }
            }

            return ['prepared' => true, 'mode' => $operation->kind, 'production' => false, 'public' => $plan['publication'] === 'public',
                'remote_router' => false, 'web_root' => false, 'targeted' => false, 'child_phases' => $phases];
        }
        $completed = ValidatedData::object($operation->state['route_completed_stages'] ?? []);
        $sequence = $this->execution->sequence($operation);
        if ($input['action'] === 'prove') {
            foreach ($sequence as $stage) {
                if (($completed[$stage] ?? false) !== true) {
                    throw new ResourceOperationException('operation.completion_unproven', 'Finish every required analytics Route phase first.', 409);
                }
                $plan = ValidatedData::object($operation->state['route_plan'] ?? []);
                foreach (RouteExecutionProof::objects(ValidatedData::object($plan['stages'])[$stage] ?? []) as $child) {
                    app(OperationChildren::class)->requireSucceeded($operation, ValidatedData::string($child['key']));
                }
            }

            return ['ready' => true, 'route_id' => $operation->input['route_id']];
        }
        $stage = ValidatedData::string($input['stage']);
        $next = array_find($sequence, static fn (string $candidate): bool => ($completed[$candidate] ?? false) !== true);
        if ($next !== $stage) {
            if (($completed[$stage] ?? false) === true) {
                return $input['action'] === 'open' ? ['opened' => true] : ['ready' => true, 'stage' => $stage];
            }
            throw new ResourceOperationException('operation.completion_unproven', 'The preceding analytics Route phase has no proof.', 409);
        }
        if ($input['action'] === 'open') {
            if ($operation->kind === 'route.analytics_remove' && $stage === 'dns' && $route !== null) {
                $this->removal->beginRemoval($route);
            }
            if ($operation->kind === 'route.analytics_host_withdraw' && $stage === 'host-withdraw' && $route !== null) {
                if ($route->hasPlacementTransition() && $this->expiry->remainingAfter($route->transition_dns_moved_at) > 0) {
                    throw new ResourceOperationException('route.dns_answers_live', 'Keep the old host serving until its private DNS answers expire.', 409);
                }
                if ($route->hasPlacementTransition()) {
                    $route->update(['replacement_step' => RouteReplacementStep::Cleanup]);
                }
            }
            if ($stage === 'public-activated' && $route !== null) {
                $route->update(['status' => RouteStatus::Activating, 'replacement_step' => RouteReplacementStep::PublicActivated]);
            }
            $operation->update(['state' => [...($operation->state ?? []), 'route_active_stage' => $stage]]);

            return ['opened' => true, 'wait_seconds' => $stage === 'await-dns-expiry' && $route !== null && $route->hasPlacementTransition()
                ? $this->expiry->remainingAfter($route->transition_dns_moved_at) : 0];
        }
        if (($operation->state['route_active_stage'] ?? null) !== $stage) {
            throw new ResourceOperationException('operation.completion_unproven', 'Open the recorded analytics Route phase before committing it.', 409);
        }
        if ($stage === 'await-dns-expiry' && $route !== null && $route->hasPlacementTransition()
            && $this->expiry->remainingAfter($route->transition_dns_moved_at) > 0) {
            throw new ResourceOperationException('route.dns_answers_live', 'Keep the old host serving until its private DNS answers expire.', 409);
        }
        foreach ($this->execution->childContext($operation)['children'] as $child) {
            app(OperationChildren::class)->requireSucceeded($operation, $child['key']);
        }
        if ($route !== null) {
            if ($stage === 'publish-sites') {
                $route->publishSites();
            } elseif ($stage === 'activate-route') {
                $route->update(['status' => RouteStatus::Active, 'failed_step' => null, 'error_code' => null]);
            } elseif ($stage === 'record') {
                $route->delete();
            }
        }
        $operation->update(['state' => [...($operation->state ?? []), 'route_active_stage' => null, 'route_completed_stages' => [...$completed, $stage => true]]]);

        return ['ready' => true, 'stage' => $stage];
    }
}
