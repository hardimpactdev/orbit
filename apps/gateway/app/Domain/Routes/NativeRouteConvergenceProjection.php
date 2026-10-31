<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Projects Swamp's bounded Route outcome. Swamp owns ordering and effect proof. */
final readonly class NativeRouteConvergenceProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return $this->publicInput($operation, ValidatedData::integer($operation->node_id));
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        if (! in_array($callerNodeId, NativeRouteConvergenceIntent::participants($operation), true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Node is not an admitted Route participant.', 403);
        }
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $this->assertPublicParticipant($operation, $callerNodeId);

        return app(NativeRouteConvergenceIntent::class)->publicInput($operation, $callerNodeId);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $wasTerminal = $operation->status->terminal();
            $result = ValidatedData::object($data['result'] ?? []);
            $operation = $this->direct->project($operation, $data, $result);
            if ($wasTerminal) {
                return $operation;
            }
            if (isset($result['checkpoint'])) {
                if ($operation->status !== OperationStatus::Running) {
                    throw $this->invalid();
                }
                $this->project($operation, $result);
            } elseif ($operation->status === OperationStatus::Succeeded) {
                $this->project($operation, $result);
            } elseif ($operation->status === OperationStatus::Failed && (ValidatedData::object($operation->input['route_convergence'] ?? [])['mode'] ?? null) !== 'state_only') {
                Route::query()->whereKey($operation->target_id)->update(['status' => RouteStatus::Failed,
                    'failed_step' => 'native-convergence', 'error_code' => $operation->error_code]);
            }

            return $operation;
        });
    }

    /** @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function project(Operation $operation, array $result): array
    {
        $intent = ValidatedData::object($operation->input['route_convergence'] ?? []);
        $routeId = ValidatedData::integer($intent['route_id']);
        if (! in_array($operation->kind, ['route.create', 'route.converge'], true) || $operation->target_type !== 'route'
            || $operation->target_id !== $routeId) {
            throw $this->invalid();
        }
        if (isset($result['checkpoint'])) {
            if (($intent['mode'] ?? null) === 'state_only' || count($result) !== 1) {
                throw $this->invalid();
            }
            $values = match ($result['checkpoint']) {
                'sites-published' => ['sites_published' => true],
                'public-activated' => ['status' => RouteStatus::Activating, 'replacement_step' => RouteReplacementStep::PublicActivated],
                'rollback-initial' => ['sites_published' => false, 'status' => RouteStatus::Failed, 'failed_step' => 'native-convergence', 'error_code' => 'route.convergence_failed'],
                default => throw $this->invalid(),
            };
            if ($result['checkpoint'] === 'rollback-initial') {
                $operation->update(['state' => [...($operation->state ?? []), 'native_route_rollback' => true]]);
            }
            Route::query()->whereKey($routeId)->update($values);

            return $result;
        }
        if (($result['route_id'] ?? null) !== $routeId) {
            throw $this->invalid();
        }
        if (($result['ready'] ?? false) === true && count($result) === 2) {
            if (($intent['mode'] ?? null) !== 'state_only') {
                Route::query()->whereKey($routeId)->update(['status' => RouteStatus::Active, 'failed_step' => null, 'error_code' => null,
                    ...(($intent['public'] ?? false) ? ['replacement_step' => RouteReplacementStep::IngressFirewall] : [])]);
            }

            return ['ready' => true, 'route_id' => $routeId];
        }
        $code = $result['error_code'] ?? null;
        if (! is_string($code) || preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $code) !== 1 || count($result) !== 2) {
            throw $this->invalid();
        }
        if (($intent['mode'] ?? null) !== 'state_only') {
            Route::query()->whereKey($routeId)->update(['status' => RouteStatus::Failed, 'failed_step' => 'native-convergence', 'error_code' => $code]);
        }

        return ['route_id' => $routeId, 'error_code' => $code];
    }

    private function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('operation.result_invalid', 'The native Route outcome must match its admitted Route.', 422);
    }
}
