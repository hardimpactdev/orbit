<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Routes\RouteTargetState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeFailedStep;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** The Gateway writes the new targets from the native result: on success, or when a step after the Router switch fails. */
final readonly class NativeRouteTargetProjection implements DirectOperationProjection, NativeOperationProjection
{
    /** Each chart step's error code. A step fails with its own exit code; its stderr carries the reason (rule 14). */
    public const array StepCodes = [
        'Make CSRs on new Nodes' => 'app-dev.certificate_request_failed',
        'Sign the CSRs' => 'app-dev.certificate_sign_failed',
        'Install cert, publish site' => 'route.publish_failed',
        'Stage the new targets' => 'route.target_stage_failed',
        'Probe each new target' => 'route.probe_failed',
        'Switch the Router pool' => 'route.router_switch_failed',
        'Retire detached targets' => 'route.target_retire_failed',
        'Move private DNS' => 'private_dns.publication_failed',
    ];

    /** Once the Router pool switched, the new targets serve; a later failure still records them. */
    private const array AfterSwitch = ['Retire detached targets', 'Move private DNS'];

    public function __construct(private DirectOperation $direct) {}

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['route_target'])['plan'], 'execution_secrets' => $operation->input['execution_secrets']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $wasTerminal = $operation->status->terminal();
            $step = NativeFailedStep::label($data, array_keys(self::StepCodes));
            if ($data['status'] === 'failed') {
                $data['error_code'] = $step === null ? ($data['error_code'] ?? 'route.target_set_failed') : self::StepCodes[$step];
            }
            $result = match ($data['status']) {
                'succeeded' => ['ready' => true, 'route_id' => $operation->target_id],
                'failed' => ['error_code' => $data['error_code'], 'failed_step' => $step],
                default => ValidatedData::object($data['result'] ?? []),
            };
            $operation = $this->direct->project($operation, $data, $result);
            if ($wasTerminal || ! $operation->status->terminal()) {
                return $operation;
            }
            $intent = ValidatedData::object($operation->input['route_target']);
            $route = Route::query()->lockForUpdate()->findOrFail(ValidatedData::integer($intent['route_id']));
            $state = app(RouteTargetState::class);
            $switched = $operation->status === OperationStatus::Succeeded || in_array($step, self::AfterSwitch, true);
            if ($switched) {
                if (! $intent['binding_only'] && ! $intent['noop']) {
                    $proposal = NativeRouteTargetIntent::proposal(ValidatedData::object($intent['proposal']));
                    foreach ($proposal->targetIds as $id) {
                        $state->associateOnRoute($route, $id);
                    }
                    foreach ($proposal->dispositions as $disposition) {
                        if ($disposition->routeId !== null) {
                            $state->reassignToRoute($disposition->instanceId, $disposition->routeId);
                        }
                    }
                    $state->compactPositions($route->id, $proposal->targetIds);
                    foreach (ValidatedData::integerList(array_column(RouteExecutionProof::objects($intent['original_route_states']), 'route_id')) as $id) {
                        if ($id !== $route->id) {
                            $state->compactPositions($id);
                        }
                    }
                    $route->update(['status' => RouteStatus::Active, 'sites_published' => true]);
                    // A failed retire keeps the recorded set, so the same command runs again and finishes it.
                    if ($operation->status === OperationStatus::Succeeded) {
                        $state->complete($route);
                    }
                }
            }
            if ($operation->status === OperationStatus::Failed) {
                $state->recordFailure($route, $step ?? 'native-target-set', $operation->error_code ?? 'route.target_set_failed');
            }

            return $operation;
        });
    }
}
