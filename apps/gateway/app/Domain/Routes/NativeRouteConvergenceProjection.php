<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;

/** Projects Swamp's bounded Route outcome. Swamp owns ordering and effect proof. */
final readonly class NativeRouteConvergenceProjection
{
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
            if (count($result) !== 1) {
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
        Route::query()->whereKey($routeId)->update(['status' => RouteStatus::Failed, 'failed_step' => 'native-convergence', 'error_code' => $code]);

        return ['route_id' => $routeId, 'error_code' => $code];
    }

    private function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('operation.result_invalid', 'The native Route outcome must match its admitted Route.', 422);
    }
}
