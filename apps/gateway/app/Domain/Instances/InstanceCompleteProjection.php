<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Logs\LogRedactor;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\NativeStepFailure;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Projects the terminal receipt of `instance.complete` once (docs/reference/development-containers.md#complete-a-clone). Success makes the
 * Route and then the clone `active`. A failed or cancelled run keeps the clone `source_resolved`, its runtime and its T3 registration.
 */
final readonly class InstanceCompleteProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return app(NativeInstanceCompleteInput::class)->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->refresh();
            $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
            $status = ValidatedData::string($data['status']);
            $result = ValidatedData::object($data['result'] ?? []);
            if (($result['failed_step'] ?? null) === 'fleet' && is_string($result['detail'] ?? null) && $result['detail'] !== '') {
                // The fleet phase says why in the run's result file; it becomes the failure's detail, redacted again, as for a create.
                $native = ValidatedData::object($data['failure'] ?? []);
                $data['failure'] = ['failed_step' => is_string($native['failed_step'] ?? null) ? $native['failed_step'] : 'clone-fleet',
                    'detail' => mb_strcut(app(LogRedactor::class)->redact($result['detail'], []), 0, NativeStepFailure::MaximumBytes, 'UTF-8'),
                    'stderr_recorded' => true];
            }
            unset($result['detail']);
            $instance = Instance::query()->lockForUpdate()->findOrFail($operation->target_id);
            // A removal admitted to start after this completion owns the clone: the run's end changes no record of it.
            if ($operation->status->terminal() || $instance->status === InstanceState::Removing) {
                return $this->direct->project($operation, $data, $result);
            }
            if ($status === 'succeeded' && (($result['ready'] ?? false) !== true || ($result['instance_id'] ?? null) !== $instance->id)) {
                throw new ResourceOperationException('instance.result_invalid', 'The completion did not prove its complete terminal result.');
            }
            $input = $operation->input;
            $route = ($input['route_id'] ?? null) === null ? null : Route::query()->lockForUpdate()->findOrFail(ValidatedData::integer($input['route_id']));
            $container = ValidatedData::object(ValidatedData::object($input['direct_provisioning'])['container']);
            if ($status === 'succeeded') {
                // An active routed Instance needs its active Route, so the Route turns active first.
                $route?->update(['status' => RouteStatus::Active, 'sites_published' => true, 'failed_step' => null, 'error_code' => null]);
                $instance->update(['status' => InstanceState::Active, 'provisioning_step' => 'active', 'first_setup_pending' => false,
                    'failed_step' => null, 'error_code' => null, 'development_projection_pending' => false]);
            } elseif (in_array($status, ['failed', 'cancelled'], true)) {
                $code = ValidatedData::nullableString($data['error_code'] ?? null) ?? ValidatedData::nullableString($result['error_code'] ?? null) ?? 'operation.cancelled';
                $instance->update(['failed_step' => $result['failed_step'] ?? 'complete', 'error_code' => $code]);
                if (($result['failed_step'] ?? null) === 'route') {
                    $route?->update(['failed_step' => 'native-create', 'error_code' => $result['error_code'] ?? 'instance.create_route_failed']);
                }
                $result['recovery'] = self::recovery($instance);
            }
            if (in_array($status, ['succeeded', 'failed', 'cancelled'], true)) {
                app(ContainerProcesses::class)->complete($instance, $container['processes'] ?? [], $status === 'succeeded',
                    ValidatedData::nullableString($data['error_code'] ?? null));
            }

            return $this->direct->project($operation, $data, $result);
        });
    }

    /**
     * A completion cancelled before its run started: the clone records the cancel as a cancelled run does, inside the cancel's
     * transaction, and the result names the same recovery.
     *
     * A removal that owns the clone keeps it as it is, and the result names no retry.
     *
     * @return array<string, mixed>|null
     */
    public static function cancelledBeforeRun(Operation $operation): ?array
    {
        $instance = Instance::query()->lockForUpdate()->findOrFail($operation->target_id);
        if ($instance->status === InstanceState::Removing) {
            return null;
        }
        $instance->update(['failed_step' => 'complete', 'error_code' => 'operation.cancelled']);

        return self::recovery($instance);
    }

    /**
     * The clone stays for a retry of the completion alone: the container runs and T3 keeps its thread.
     *
     * @return array<string, mixed>
     */
    public static function recovery(Instance $instance): array
    {
        return ['state' => 'retained_for_retry',
            'retry' => ['command' => 'instance:complete', 'instance_id' => $instance->id, 'new_idempotency_key' => true],
            'destroy' => ['command' => 'instance:destroy', 'instance_id' => $instance->id, 'available' => true, 'options' => ['yes' => true, 'force' => true]]];
    }
}
