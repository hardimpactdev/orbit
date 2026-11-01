<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Stored serving state follows the native terminal observation only. */
final readonly class NativeRouteConvergenceProjection implements DirectOperationProjection, NativeOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['route_lifecycle'], 'execution_secrets' => $operation->input['execution_secrets']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $wasTerminal = $operation->status->terminal();
            $result = ValidatedData::object($data['result'] ?? []);
            $operation = $this->direct->project($operation, $data, $result);
            if ($wasTerminal || ! $operation->status->terminal()) {
                return $operation;
            }
            $intent = ValidatedData::object($operation->input['route_lifecycle']);
            if ($intent['effectful'] && $operation->status === OperationStatus::Succeeded) {
                Route::query()->whereKey($operation->target_id)->update(['status' => RouteStatus::Active, 'sites_published' => true,
                    'replacement_step' => $intent['public_activated'] ? RouteReplacementStep::IngressFirewall : null,
                    'failed_step' => null, 'error_code' => null]);
            } elseif ($intent['effectful'] && $operation->status === OperationStatus::Failed) {
                Route::query()->whereKey($operation->target_id)->update(['failed_step' => 'native-create', 'error_code' => $operation->error_code]);
            }
            if ($operation->status === OperationStatus::Succeeded || ($result['restored'] ?? false)) {
                app(NativeRouteLanInput::class)->recordMigrations(ValidatedData::integerList($result['migrated_node_ids'] ?? []), ValidatedData::integerList($result['migrated_node_ids'] ?? []));
            }

            return $operation;
        });
    }
}
