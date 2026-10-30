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
            return array_intersect_key($operation->input, array_flip(['instance_id', 'project_id', 'list', 'execution', 'steps']));
        }

        return $this->intent->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = $data['status'] ?? null;
        $result = ValidatedData::object($data['result'] ?? []);
        $code = $data['error_code'] ?? null;
        $phase = $operation->input['list'] ?? null;
        $failed = $result['failed_step'] ?? null;
        $names = array_map(static fn (array $step): string => $phase.'.'.$step['name'], $operation->input['steps'] ?? []);
        $valid = match ($status) {
            'succeeded' => $code === null && $result === ['instance_id' => $operation->target_id, 'outcome' => 'succeeded', 'no_op' => ($names === [])],
            'failed' => is_string($code) && preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $code) === 1
                && array_diff(array_keys($result), ['instance_id', 'outcome', 'error_code', 'failed_step']) === []
                && ($result['instance_id'] ?? null) === $operation->target_id && ($result['error_code'] ?? null) === $code
                && in_array($result['outcome'] ?? null, ['failed', 'missing', 'busy', 'deadline', 'unconfirmed'], true)
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
