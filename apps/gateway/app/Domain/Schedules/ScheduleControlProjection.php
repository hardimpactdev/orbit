<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Domain\Instances\Deployment\ProductionContainerRelease;
use App\Domain\Instances\Deployment\ProductionContainerUnits;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationWorkflowChoice;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Schedule;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Safe admitted input and bounded Schedule domain projection only. */
final readonly class ScheduleControlProjection implements DirectOperationProjection, DirectOperationWorkflowChoice
{
    public function __construct(private DirectOperation $direct, private ScheduleOperationCompletion $completion) {}

    public function workflow(Operation $operation): string
    {
        if (isset($operation->input['production_control']) && $operation->kind !== 'schedule.run') {
            return 'production-schedule-control';
        }

        return match ($operation->kind) {
            'schedule.create' => 'operation-schedule-create',
            'schedule.destroy' => 'operation-schedule-destroy',
            'schedule.run' => 'task-command-run',
            default => 'operation-schedule-control',
        };
    }

    public function input(Operation $operation): array
    {
        if (! $operation->status->terminal() && ! Schedule::query()->whereKey($operation->input['id'])->exists()) {
            throw new ResourceOperationException('schedule.target_unavailable', 'The admitted Schedule no longer exists.', 404);
        }

        if ($operation->target_type === 'instance_schedules') {
            $instance = Instance::query()->findOrFail($operation->target_id);
            $target = ProductionContainerRelease::target($instance);
            if (($operation->input['production_control'] ?? null) != $target) {
                throw new ResourceOperationException('schedule.target_unavailable', 'The Schedule target changed after admission.', 409);
            }
            if ($target !== null && $operation->kind !== 'schedule.run') {
                $id = ValidatedData::string($operation->input['id']);
                $schedule = Schedule::query()->whereIn('target_type', Instance::morphTypes())->where('target_id', $instance->id)
                    ->whereKey($id)->firstOrFail();
                $action = substr($operation->kind, strlen('schedule.'));
                $units = $action === 'create' ? array_values(array_filter(app(ProductionContainerUnits::class)->forInstance($instance)['units'],
                    fn (array $u): bool => in_array($u['name'], ['orbit-schedule-'.$id.'.service', 'orbit-schedule-'.$id.'.timer'], true))) : [];
                $plan = ['instance_id' => $instance->id, 'release' => $target['release'], 'id' => $schedule->id, 'action' => $action, 'units' => $units];

                return ['intent' => ['target' => $target, 'id' => $id, 'action' => $action, 'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node)],
                    'execution_secrets' => ['control' => app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'schedule-control-operation-'.$operation->id, json_encode($plan, JSON_THROW_ON_ERROR))]];
            }
        }

        if ($operation->kind === 'schedule.enable') {
            return ['intent' => ['schedule' => ['id' => $operation->input['id']],
                'ssh_execution' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($operation->node_id))],
                'execution_secrets' => []];
        }

        if (in_array($operation->kind, ['schedule.create', 'schedule.destroy'], true)) {
            return ['intent' => ['schedule' => Arr::only($operation->input, ['id', 'command_hash', 'user', 'group', 'home', 'working_directory', 'shell', 'login_shell', 'calendar', 'timeout_seconds', 'desired']),
                'cascade' => $operation->input['cascade'] ?? false,
                'ssh_execution' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($operation->node_id))],
                'execution_secrets' => []];
        }

        if ($operation->kind === 'schedule.run') {
            $source = app(ScheduleOperationSecrets::class);
            $owner = $source->ownerId($operation);
            if ($owner === null) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The Schedule command does not belong to this target.', 422);
            }
            $ssh = app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($operation->node_id));
            $command = $source->resolve($operation, $owner, 'command', 'command');

            return ['intent' => ['schedule' => [...Arr::only($operation->input, ['id', 'command_hash', 'user', 'login_shell', 'timeout_seconds']),
                'working_directory' => $operation->input['working_directory'] ?? '', 'shell' => $operation->input['shell'] ?? ''],
                'ssh_execution' => $ssh, ...Arr::only($operation->input, ['container', 'production_control'])],
                'execution_secrets' => ['command' => app(SwampCommandStep::class)->executionCopy(
                    'orbit-control-inputs', 'schedule-run-operation-'.$operation->id, ValidatedData::string($command->value),
                )]];
        }

        return Arr::only($operation->input, ['id', 'command_hash', 'user', 'group', 'home', 'working_directory', 'shell', 'login_shell', 'calendar', 'timeout_seconds', 'desired', 'cascade']);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        if ($operation->kind === 'schedule.run' && $code === 'operation.native_failed') {
            $code = 'schedule.run_failed';
            $data['error_code'] = $code;
        }
        $result = match ($status) {
            OperationStatus::Succeeded => ['ready' => true],
            OperationStatus::Failed, OperationStatus::Interrupted => ['error_code' => $code],
            default => [],
        };

        return DB::transaction(function () use ($operation, $data, $status, $code, $result): Operation {
            $operation->refresh();
            $terminal = $operation->status->terminal();
            $projected = $this->direct->project($operation, $data, $result);
            if (! $terminal && $status->terminal()) {
                $outcome = match ($status) {
                    OperationStatus::Succeeded => OperationOutcome::succeeded($result),
                    OperationStatus::Failed => OperationOutcome::failed(ValidatedData::string($code), $result),
                    OperationStatus::Cancelled => OperationOutcome::cancelled(),
                    OperationStatus::Interrupted => OperationOutcome::interrupted($code, $result),
                    default => throw new \LogicException('A terminal Schedule status is required.'),
                };
                $this->completion->complete($projected, $outcome);
            }

            return $projected;
        });
    }
}
