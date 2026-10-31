<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Actions\Operations\StartOperationAction;
use App\Data\Routes\UpdateRouteData;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Routes\AnalyticsRouteParentCommand;
use App\Domain\Routes\DirectRoutePublication;
use App\Domain\Routes\GeneratedRouteUpdateOwnership;
use App\Domain\Routes\RouteKitAvailability;
use App\Domain\Routes\RouteNodeEffectExecution;
use App\Domain\Routes\RouteOperationProjection;
use App\Domain\Routes\RoutePublication;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Called by B only after binding the parent's currently eligible server-owned child intent. */
final readonly class RouteChildAdmission implements OperationChildAdmission
{
    public function __construct(private AdmitRouteOperationAction $routes, private StartRouteProjectionAction $projections,
        private StartOperationAction $operations, private GeneratedRouteUpdateOwnership $generatedUpdates) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        return DB::transaction(function () use ($parent, $intent): Operation {
            $input = $intent['input'];
            $kind = $intent['kind'];
            if ($kind === 'route.destroy') {
                // In particular, AI takeover exposes this key only after the collector health proof.
                // Planning this intent never creates a runnable destruction Operation.
                $operation = $this->routes->destroy($this->route($input), $this->offline($input), requestId: $parent->request_id);
            } elseif ($kind === 'route.update') {
                $route = $this->route($input);
                $allowGenerated = $this->generatedUpdates->allows($parent, $route, $intent);
                $data = new UpdateRouteData(array_key_exists('domain', $input), ValidatedData::nullableString($input['domain'] ?? null),
                    array_key_exists('publication', $input), isset($input['publication']) ? RoutePublication::from(ValidatedData::string($input['publication'])) : null,
                    array_key_exists('web_root', $input), ValidatedData::nullableString($input['web_root'] ?? null));
                $operation = $this->routes->update($route, $data, requestId: $parent->request_id, allowGenerated: $allowGenerated);
            } elseif ($kind === 'route.converge') {
                $operation = $this->projections->converge($this->route($input), $this->instance($input), $parent->request_id);
            } elseif ($kind === 'route.withdraw') {
                $operation = $this->projections->withdraw($this->route($input), $this->offline($input), $parent->request_id);
            } elseif ($kind === 'route.retire_source') {
                $operation = $this->projections->retireSource(InstanceTransfer::query()->findOrFail(ValidatedData::string($input['transfer_id'])), $parent->request_id);
            } elseif ($kind === 'route.withdraw_instance' || $kind === 'route.cleanup_instance') {
                $instance = $this->instance($input);
                $operation = $kind === 'route.withdraw_instance' ? $this->projections->withdrawInstance($instance, $parent->request_id)
                    : $this->projections->cleanupInstance($instance, $parent->request_id);
            } else {
                $node = Node::query()->findOrFail(ValidatedData::integer($intent['node_id']));
                if (in_array($kind, [...RouteOperationProjection::Kinds, ...RouteOperationProjection::LocalKinds], true)) {
                    $operation = app(StartRouteNodeOperationAction::class)->execute($kind, $node, $input, $parent);
                } elseif (in_array($kind, ['route.caddy_converge', 'route.private_dns_publish', 'route.offline_residue_clean'], true)) {
                    if (DirectRoutePublication::handles($kind) && app(DirectOperation::class)->writer($kind) !== 'direct') {
                        throw new ResourceOperationException('operation.kind_unavailable', 'Route publication requires its direct Swamp writer.', 409);
                    }
                    $operation = $this->operations->execute($kind, $kind === 'route.offline_residue_clean' ? 'node_route_residue' : 'node_route_projection', $node->id,
                        $node->id, $input, $parent->triggered_by, $parent->request_id);
                } elseif ($kind === 'route.workload_prepare' || in_array($kind, RouteNodeEffectExecution::Kinds, true)) {
                    $targetId = isset($input['route_id']) ? ValidatedData::integer($input['route_id']) : $parent->target_id;
                    $operation = $this->operations->execute($kind, $kind === 'route.workload_prepare' ? 'route_workload' : 'route_effect', $targetId,
                        $node->id, $input, $parent->triggered_by, $parent->request_id);
                } elseif (in_array($kind, ['route.analytics_publish', 'route.analytics_remove', 'route.analytics_host_prepare', 'route.analytics_host_withdraw'], true)) {
                    RouteKitAvailability::kind($kind);
                    $route = $this->route($input);
                    if (! $route->isAnalyticsTracking()) {
                        throw new ResourceOperationException('route.kind_unsupported', 'The analytics child requires a tracking Route.', 409);
                    }
                    $operation = $this->operations->execute($kind, 'route', $route->id, $node->id, ['route_id' => $route->id], $parent->triggered_by, $parent->request_id);
                    app(AnalyticsRouteParentCommand::class)->run($operation, ['action' => 'prepare']);
                } else {
                    throw new ResourceOperationException('operation.execution_forbidden', 'This is not an owned Route child kind.', 422);
                }
            }
            if ($operation->node_id !== $intent['node_id']) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The child Node must match normal Route admission.', 422);
            }

            return $operation;
        });
    }

    /** @param array<string, mixed> $input */
    private function route(array $input): Route
    {
        return Route::query()->findOrFail(ValidatedData::integer($input['route_id']));
    }

    /** @param array<string, mixed> $input */
    private function instance(array $input): Instance
    {
        return Instance::query()->findOrFail(ValidatedData::integer($input['instance_id']));
    }

    /** @param array<string, mixed> $input */
    private function offline(array $input): bool
    {
        $offline = $input['offline'] ?? false;
        if (! is_bool($offline)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Route offline intent must be a boolean.', 422);
        }

        return $offline;
    }
}
