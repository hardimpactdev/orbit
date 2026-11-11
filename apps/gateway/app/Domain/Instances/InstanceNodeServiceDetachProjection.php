<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Delete only after confirmed withdrawal; failures retain the attachment for retry. No environment mutation. */
final readonly class InstanceNodeServiceDetachProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input, 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $wasTerminal = $operation->status->terminal();
            $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
            $instance = Instance::query()->lockForUpdate()->findOrFail($operation->target_id);
            $native = ValidatedData::object($data['result'] ?? []);
            $result = isset($native['access'])
                ? ValidatedData::object(json_decode(ValidatedData::string($native['access']), true, flags: JSON_THROW_ON_ERROR)) : [];
            $succeeded = ($data['status'] ?? null) === 'succeeded';
            if ($succeeded && (NativeRunResult::stepStatus($native, 'Revoke container access') !== 'succeeded'
                || ($result['detached'] ?? false) !== true || ($result['target_id'] ?? null) !== $operation->input['target_id'])) {
                throw new ResourceOperationException('instance.result_invalid', 'The detach did not prove access withdrawal.');
            }
            $projected = $this->direct->project($operation, [...$data, 'error_code' => $result['error_code'] ?? $data['error_code'] ?? null],
                $succeeded ? ValidatedData::object($operation->input['attachment']) : $result);
            if ($succeeded && ! $wasTerminal) {
                $instance->nodeServiceTargets()->whereKey($operation->input['target_id'])->delete();
            }

            return $projected;
        });
    }
}
