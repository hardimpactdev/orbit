<?php

declare(strict_types=1);

namespace App\Domain\Instances\StepLists;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Project list admission and bounded result/state projection. Swamp runs every step. */
final readonly class InstanceSetupProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private InstanceStepListIntent $intent) {}

    public function input(Operation $operation): array
    {
        if ($operation->status->terminal()) {
            return array_intersect_key($operation->input, array_flip(['instance_id', 'project_id', 'environment', 'list', 'execution', 'steps']));
        }

        return $this->intent->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = $data['status'] ?? null;
        $result = ValidatedData::object($data['result'] ?? []);
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        $phase = ValidatedData::string($operation->input['list'] ?? null);
        $failed = $result['failed_step'] ?? null;
        $steps = $operation->input['steps'] ?? [];
        if (! is_array($steps) || ! array_is_list($steps)) {
            throw new ResourceOperationException('operation.result_invalid', 'The admitted step list is invalid.', 422);
        }
        $names = [];
        foreach ($steps as $step) {
            $row = ValidatedData::object($step);
            $names[] = $phase.'.'.ValidatedData::string($row['name'] ?? null);
        }
        $valid = match ($status) {
            'succeeded' => $code === null && $result === ['instance_id' => $operation->target_id, 'outcome' => 'succeeded', 'no_op' => ($names === [])],
            'failed' => is_string($code) && preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $code) === 1
                && array_diff(array_keys($result), ['instance_id', 'outcome', 'error_code', 'failed_step']) === []
                && ($result['instance_id'] ?? null) === $operation->target_id && ($result['error_code'] ?? null) === $code
                && match ($result['outcome'] ?? null) {
                    'failed' => $code === 'instance.'.$phase.'_step_failed',
                    'missing' => $code === 'instance.'.$phase.'_step_unavailable',
                    'busy' => $code === 'instance.lifecycle_busy',
                    'deadline' => $code === 'command.deadline_exceeded',
                    'unconfirmed' => true,
                    default => false,
                }
            && array_key_exists('failed_step', $result) && ($failed === null || in_array($failed, $names, true)),
            'running', 'cancelled' => $code === null && $result === [],
            default => false,
        };
        if (! $valid) {
            throw new ResourceOperationException('operation.result_invalid', 'The step-list result does not match its admitted outcome.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $result, $code): Operation {
            $terminal = $operation->refresh()->status->terminal();
            $projected = $this->direct->project($operation, $data, $result, 'lifecycle');
            if (! $terminal) {
                app(InstanceSetupState::class)->project($projected, $projected->status, $code);
            }

            return $projected;
        });
    }
}
