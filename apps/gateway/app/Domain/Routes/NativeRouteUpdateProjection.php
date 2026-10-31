<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;

/** State projections requested by the native update DAG, without effect revalidation. */
final readonly class NativeRouteUpdateProjection
{
    /** @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function project(Operation $operation, array $result): array
    {
        $intent = ValidatedData::object($operation->input['route_update'] ?? []);
        $routeId = ValidatedData::integer($intent['route_id']);
        $resultId = ValidatedData::integer($intent['result_route_id']);
        $desired = ValidatedData::object($intent['desired']);
        $previous = ValidatedData::object($intent['previous']);
        if ($operation->kind !== 'route.update' || $operation->target_type !== 'route' || $operation->target_id !== $routeId) {
            throw $this->invalid();
        }
        if (isset($result['checkpoint'])) {
            if (count($result) !== 1) {
                throw $this->invalid();
            }
            $values = match ($result['checkpoint']) {
                'sites-published' => ['sites_published' => true, ...($resultId !== $routeId ? ['replacement_step' => RouteReplacementStep::RouterCertificate] : [])],
                'web-root-stored' => ['web_root' => $desired['web_root']],
                'rollback-web-root-stored' => ['web_root' => $previous['web_root']],
                'publication-stored' => ['publication' => $desired['publication'], 'replacement_step' => null],
                'rollback-publication-stored' => ['publication' => $previous['publication'], 'replacement_step' => null],
                'public-activated' => ['status' => RouteStatus::Activating, 'replacement_step' => RouteReplacementStep::PublicActivated],
                'database-cutover' => ['status' => RouteStatus::Activating, 'replacement_step' => RouteReplacementStep::DatabaseCutover],
                'cleanup' => ['replacement_step' => RouteReplacementStep::Cleanup],
                'rollback-sites' => ['sites_published' => false, 'status' => RouteStatus::Failed, 'failed_step' => 'native-update', 'error_code' => 'route.update_failed'],
                default => throw $this->invalid(),
            };
            if ($result['checkpoint'] === 'database-cutover') {
                Route::query()->whereKey($routeId)->update(['status' => RouteStatus::Retiring]);
            }
            if (str_starts_with($result['checkpoint'], 'rollback-')) {
                $operation->update(['state' => [...($operation->state ?? []), 'native_route_rollback' => true]]);
            }
            Route::query()->whereKey($resultId)->update($values);

            return $result;
        }
        if (($result['route_id'] ?? null) !== $resultId) {
            throw $this->invalid();
        }
        if (($result['ready'] ?? false) === true && count($result) === 2) {
            if ($intent['mode'] !== 'state_only') {
                Route::query()->whereKey($resultId)->update(['status' => $intent['pending'] ? RouteStatus::Pending : ($resultId !== $routeId ? RouteStatus::Active : RouteStatus::from($previous['status'])),
                    'failed_step' => null, 'error_code' => null, 'replacement_step' => $desired['public'] ? RouteReplacementStep::IngressFirewall : null,
                    ...($resultId !== $routeId ? ['replaces_route_id' => null] : [])]);
            }
            if ($resultId !== $routeId) {
                Route::query()->whereKey($routeId)->update(['replaced_by_route_id' => null]);
                Route::query()->whereKey($routeId)->delete();
            }

            return ['ready' => true, 'route_id' => $resultId];
        }
        $code = $result['error_code'] ?? null;
        if (! is_string($code) || preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $code) !== 1 || count($result) !== 2) {
            throw $this->invalid();
        }
        if ($intent['mode'] !== 'web_root' && $intent['mode'] !== 'state_only') {
            Route::query()->whereKey($resultId)->update(['status' => RouteStatus::Failed, 'failed_step' => 'native-update', 'error_code' => $code]);
        }

        return ['route_id' => $resultId, 'error_code' => $code];
    }

    private function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('operation.result_invalid', 'The native Route update outcome must match its admitted Route.', 422);
    }
}
