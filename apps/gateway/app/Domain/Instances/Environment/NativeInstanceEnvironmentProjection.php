<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\Swamp\NativeRunResult;
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

    /** The public result and error code. Key names come from the run; values never do.
     * @param  array<string,mixed>  $data
     * @return array{0:?string,1:array<string,mixed>}
     */
    private function outcome(Operation $operation, array $data): array
    {
        $run = ValidatedData::object($data['result'] ?? []);
        $plan = ValidatedData::object($operation->input['direct_environment']);
        $status = $data['status'] ?? null;
        $read = is_array($run['steps']['Read the key names'] ?? null) && is_string($run['steps']['Read the key names']['stdout'] ?? null)
            ? explode("\n", $run['steps']['Read the key names']['stdout']) : [];
        if (in_array($status, [OperationStatus::Failed->value, OperationStatus::Interrupted->value], true)) {
            if (NativeRunResult::stepStatus($run, 'Check for foreign keys') === 'failed') {
                $known = [...ValidatedData::stringList($plan['keys']), ...ValidatedData::stringList($plan['owned_keys'])];
                $foreign = array_values(array_unique(array_filter($read, static fn (string $key): bool => $key !== '' && ! str_starts_with($key, '#') && ! in_array($key, $known, true))));
                sort($foreign, SORT_STRING);

                return ['env.sync_would_drop_keys', ['error_code' => 'env.sync_would_drop_keys', 'foreign_keys' => $foreign]];
            }
            $refresh = array_filter(is_array($run['steps'] ?? null) ? $run['steps'] : [], static fn (mixed $step, int|string $name): bool => str_starts_with((string) $name, 'Refresh the runtime')
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
        $before = [];
        foreach ($read as $line) {
            if (preg_match('/\A#sha ([a-f0-9]{64})  (\.env(?:\.testing)?)\z/D', $line, $match) === 1) {
                $before[$match[2]] = $match[1];
            }
        }
        $environmentChanged = ($before['.env'] ?? null) !== $plan['sha256'];
        $testingStatus = $testing === null ? null : (in_array('#tracked', $read, true) ? 'skipped_tracked'
            : (($before['.env.testing'] ?? null) === $testing['sha256'] ? 'unchanged' : 'written'));

        return [null, ['instance_id' => $operation->target_id, 'changed' => $environmentChanged || $testingStatus === 'written',
            'keys' => $keys, 'key_count' => count($keys), 'test_database' => $testing['test_database'] ?? null,
            'environment_changed' => $environmentChanged, 'testing_status' => $testingStatus,
            'runtime_refresh' => $runtime === null ? 'skipped_development' : ($runtime['config'] === null ? 'skipped_no_php_version' : 'refreshed'),
            'restarted_process_ids' => $runtime === null ? [] : array_map(static fn (mixed $process): int => ValidatedData::integer(ValidatedData::object($process)['id']), array_values(is_array($runtime['processes']) ? $runtime['processes'] : []))]];
    }
}
