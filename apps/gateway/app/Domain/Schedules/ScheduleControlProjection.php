<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Schedule;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Safe admitted input and bounded Schedule domain projection only. */
final readonly class ScheduleControlProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private ScheduleOperationCompletion $completion) {}

    public function input(Operation $operation): array
    {
        if (! $operation->status->terminal() && ! Schedule::query()->whereKey($operation->input['id'])->exists()) {
            throw new ResourceOperationException('schedule.target_unavailable', 'The admitted Schedule no longer exists.', 404);
        }

        if ($operation->kind === 'schedule.run') {
            $source = app(ScheduleOperationSecrets::class);
            $owner = $source->ownerId($operation);
            if ($owner === null) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The Schedule command does not belong to this target.', 422);
            }
            $ssh = app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($operation->node_id));
            $command = $source->resolve($operation, $owner, 'command', 'command');

            return ['intent' => ['schedule' => Arr::only($operation->input, ['id', 'command_hash', 'user', 'group', 'home', 'working_directory', 'shell', 'login_shell', 'timeout_seconds']),
                'ssh_execution' => $ssh],
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
        $result = match ($status) {
            OperationStatus::Succeeded => ['ready' => true],
            OperationStatus::Failed => ['error_code' => $code],
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
                    default => throw new \LogicException('A terminal Schedule status is required.'),
                };
                $this->completion->complete($projected, $outcome);
            }

            return $projected;
        });
    }
}
