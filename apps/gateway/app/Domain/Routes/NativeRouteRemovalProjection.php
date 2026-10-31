<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;

/** Records Swamp outcomes without repeating its execution decisions or proof. */
final readonly class NativeRouteRemovalProjection
{
    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function project(Operation $operation, array $data): array
    {
        $result = ValidatedData::object($data['result'] ?? []);
        $route = Route::query()->find($operation->target_id);
        $status = OperationStatus::from(ValidatedData::string($data['status'] ?? 'running'));
        if ($status === OperationStatus::Succeeded) {
            app(NativeRouteRemovalCompletion::class)->apply(
                ValidatedData::object($operation->input['route_removal']),
                $operation->kind === 'route.destroy',
                array_values(array_map(ValidatedData::object(...), $result['retained_on_nodes'] ?? [])),
            );
        } elseif ($status === OperationStatus::Failed) {
            $route?->update(['status' => RouteStatus::Failed, 'failed_step' => $result['failed_step'] ?? 'withdrawal',
                'error_code' => $data['error_code'] ?? 'route.withdrawal_failed']);
        }

        return $result;
    }
}
