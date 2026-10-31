<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records the native outcome; no Gateway probe, effect or completion contract. */
final readonly class NativeInstanceEnvironmentProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return ['instance_id' => $operation->target_id, 'direct_environment' => $operation->input['direct_environment']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $wasTerminal = $operation->refresh()->status->terminal();
            $result = ValidatedData::object($data['result'] ?? []);
            $projected = $this->direct->project($operation, $data, $result);
            if (! $wasTerminal && ($data['status'] ?? null) === OperationStatus::Succeeded->value) {
                $instance = Instance::query()->findOrFail($operation->target_id);
                $keys = ValidatedData::stringList($result['keys'] ?? []);
                sort($keys, SORT_STRING);
                $instance->forceFill(['environment_owned_keys' => array_values(array_unique($keys)),
                    'environment_runtime_pending_at' => null])->saveOrFail();
            }

            return $projected;
        });
    }
}
