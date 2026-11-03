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

/** Builds the public result from Swamp's own run data; no Gateway probe, effect or completion contract. */
final readonly class NativeInstanceEnvironmentProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private NativeInstanceEnvironmentSync $sync) {}

    public function input(Operation $operation): array
    {
        return $this->sync->inputs($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $wasTerminal = $operation->refresh()->status->terminal();
            [$code, $result] = $this->outcome($operation, $data);
            $projected = $this->direct->project($operation, [...$data, 'error_code' => $code], $result);
            if (! $wasTerminal && ($data['status'] ?? null) === OperationStatus::Succeeded->value) {
                $instance = Instance::query()->findOrFail($operation->target_id);
                $keys = ValidatedData::stringList(ValidatedData::object($operation->input['direct_environment'])['keys']);
                sort($keys, SORT_STRING);
                $instance->forceFill(['environment_owned_keys' => array_values(array_unique($keys)),
                    'environment_runtime_pending_at' => null])->saveOrFail();
            }

            return $projected;
        });
    }

    /** The public result and error code, from the plan and the file behavior's status of each file; never a value.
     * @param  array<string,mixed>  $data
     * @return array{0:?string,1:array<string,mixed>}
     */
    private function outcome(Operation $operation, array $data): array
    {
        $run = ValidatedData::object($data['result'] ?? []);
        $plan = ValidatedData::object($operation->input['direct_environment']);
        $status = $data['status'] ?? null;
        $steps = is_array($run['steps'] ?? null) ? $run['steps'] : [];
        if (in_array($status, [OperationStatus::Failed->value, OperationStatus::Interrupted->value], true)) {
            $refresh = array_filter($steps, static fn (mixed $step, int|string $name): bool => str_starts_with((string) $name, 'Refresh the runtime')
                && is_array($step) && ($step['status'] ?? null) === 'failed', ARRAY_FILTER_USE_BOTH);
            $code = $refresh !== [] ? 'env.runtime_refresh_failed'
                : ValidatedData::nullableString($data['error_code'] ?? null) ?? 'operation.native_failed';

            return [$code, ['error_code' => $code]];
        }
        if ($status !== OperationStatus::Succeeded->value) {
            return [ValidatedData::nullableString($data['error_code'] ?? null), []];
        }
        $keys = ValidatedData::stringList($plan['keys']);
        $testing = $plan['testing'] === null ? null : ValidatedData::object($plan['testing']);
        $runtime = $plan['runtime'] === null ? null : ValidatedData::object($plan['runtime']);
        $environmentChanged = ($run['environment_write'] ?? null) !== 'compliant';
        $testingStatus = is_string($plan['testing_skipped'] ?? null) ? $plan['testing_skipped']
            : ($testing === null ? null : (($run['testing_write'] ?? null) === 'compliant' ? 'unchanged' : 'written'));

        return [null, ['instance_id' => $operation->target_id, 'changed' => $environmentChanged || $testingStatus === 'written',
            'keys' => $keys, 'key_count' => count($keys), 'test_database' => $testing['test_database'] ?? null,
            'environment_changed' => $environmentChanged, 'testing_status' => $testingStatus,
            'runtime_refresh' => $runtime === null ? 'skipped_development' : ($runtime['config'] === null ? 'skipped_no_php_version' : 'refreshed'),
            'restarted_process_ids' => $runtime === null ? [] : array_map(static fn (mixed $process): int => ValidatedData::integer(ValidatedData::object($process)['id']), array_values(is_array($runtime['processes']) ? $runtime['processes'] : []))]];
    }
}
