<?php

declare(strict_types=1);

namespace App\Domain\Instances\StepLists;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Project list admission and bounded result/state projection. Swamp runs every step. */
final readonly class InstanceSetupProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private InstanceStepListIntent $intent) {}

    public function input(Operation $operation): array
    {
        return $this->intent->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        [$code, $result] = $this->outcome($operation, $data);
        $data = [...$data, 'error_code' => $code];

        return DB::transaction(function () use ($operation, $data, $result, $code): Operation {
            $terminal = $operation->refresh()->status->terminal();
            $projected = $this->direct->project($operation, $data, $result);
            if (! $terminal) {
                app(InstanceSetupState::class)->project($projected, $projected->status, $code);
            }

            return $projected;
        });
    }

    /** The public outcome from Swamp's run data: the first failed command names the step; exit 124 is its deadline, 127 a missing command.
     * @param  array<string,mixed>  $data
     * @return array{0:?string,1:array<string,mixed>}
     */
    private function outcome(Operation $operation, array $data): array
    {
        $phase = ValidatedData::string($operation->input['list'] ?? null);
        $names = array_map(static fn (mixed $step): string => ValidatedData::string(ValidatedData::object($step)['name'] ?? null),
            array_values(is_array($operation->input['steps'] ?? null) ? $operation->input['steps'] : []));
        $base = ['instance_id' => $operation->target_id];
        $status = $data['status'] ?? null;
        if ($status === OperationStatus::Succeeded->value) {
            return [null, [...$base, 'outcome' => 'succeeded', 'no_op' => $names === []]];
        }
        if (! in_array($status, [OperationStatus::Failed->value, OperationStatus::Interrupted->value], true)) {
            return [null, []];
        }
        $run = ValidatedData::object($data['result'] ?? []);
        $steps = is_array($run['steps'] ?? null) ? $run['steps'] : [];
        foreach ($names as $index => $name) {
            $step = $steps['Run the setup steps-'.$index] ?? null;
            if (! is_array($step) || ($step['status'] ?? null) !== 'failed') {
                continue;
            }
            $exit = is_int($step['exit_code'] ?? null) ? $step['exit_code'] : null;
            [$outcome, $code] = match ($exit) {
                124 => ['deadline', 'command.deadline_exceeded'],
                127 => ['missing', 'instance.'.$phase.'_step_unavailable'],
                default => ['failed', 'instance.'.$phase.'_step_failed'],
            };

            return [$code, [...$base, 'outcome' => $outcome, 'error_code' => $code, 'failed_step' => $phase.'.'.$name,
                ...($exit === null ? [] : ['exit_code' => $exit])]];
        }

        return ['instance.step_execution_unconfirmed', [...$base, 'outcome' => 'unconfirmed', 'error_code' => 'instance.step_execution_unconfirmed', 'failed_step' => null]];
    }
}
