<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationResultFields;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\TaskVms\TaskVmInputs;
use App\Models\Operation;
use App\Models\PackageCacheEntry;
use App\Models\PackageCacheSnapshot;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Projects bounded native cache results against the admitted lockfile snapshots. */
final readonly class TaskVmPackageCacheProjection implements DirectOperationProjection
{
    public function __construct(private TaskVmSettings $settings) {}

    /** @return list<string|array<string,mixed>> */
    public static function resultFields(): array
    {
        return ['ready', 'error_code', 'replace_inventory', ...array_map(static fn (string $name): array => ['name' => $name, 'type' => 'list', 'items' => 'string', 'max' => 4096],
            ['archive_ids', 'archive_digests', 'failed_ids', 'failure_reasons'])];
    }

    public function input(Operation $operation): array
    {
        $input = $operation->input;
        if ($operation->kind !== 'task_vm.fill_package_cache' || $operation->target_type !== 'task_vm_cache'
            || $operation->node_id !== $operation->target_id || ($input['host_node_id'] ?? null) !== $operation->node_id
            || ! is_string($input['agent_sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $input['agent_sha256']) !== 1) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Cache intent requires its exact admitted host and agent digest.', 422);
        }

        $this->settings->host(ValidatedData::integer($operation->node_id));

        return [...Arr::only($input, ['agent_sha256', 'artifacts', 'max_bytes']), 'conflicts' => (object) ValidatedData::stringMap($input['conflicts'])];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->input($operation);
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        $result = ValidatedData::object($data['result'] ?? []);
        OperationResultFields::filter(self::resultFields(), $result);
        $batch = $status === OperationStatus::Running && $result !== [];
        $valid = match ($status) {
            OperationStatus::Succeeded => $result === ['ready' => true] && $code === null,
            OperationStatus::Failed => $code === 'task_vm.package_cache_failed' && $result === ['error_code' => $code],
            OperationStatus::Running => $code === null,
            OperationStatus::Cancelled => $result === [] && $code === null,
            default => false,
        };
        if (! $valid) {
            $this->refuse();
        }
        $hashes = [];
        $failures = [];
        if ($batch) {
            if (array_diff(array_keys($result), ['replace_inventory', 'archive_ids', 'archive_digests', 'failed_ids', 'failure_reasons']) !== [] || count($result) !== 5 || ! is_bool($result['replace_inventory'] ?? null)) {
                $this->refuse();
            }
            $keys = ValidatedData::stringList($result['archive_ids']);
            $values = ValidatedData::stringList($result['archive_digests']);
            $failed = ValidatedData::stringList($result['failed_ids']);
            $reasons = ValidatedData::stringList($result['failure_reasons']);
            $all = [...$keys, ...$failed];
            $allowed = [...array_column(TaskVmInputs::artifacts($operation->input['artifacts']), 'key'), ...array_keys(ValidatedData::stringMap($operation->input['conflicts']))];
            if (count($all) > 4096 || count($all) !== count(array_unique($all)) || count($keys) !== count($values) || count($failed) !== count($reasons)
                || array_diff($all, $allowed) !== [] || array_intersect($keys, array_keys(ValidatedData::stringMap($operation->input['conflicts']))) !== []
                || array_any($values, static fn (string $hash): bool => preg_match('/\Asha256-[a-f0-9]{64}\z/D', $hash) !== 1)
                || array_any($reasons, static fn (string $reason): bool => preg_match('/\A[\x20-\x7e]{1,160}\z/D', $reason) !== 1)) {
                $this->refuse();
            }
            $hashes = array_combine($keys, $values);
            $failures = array_combine($failed, array_map(new CommandActivityInputSanitizer()->redactText(...), $reasons));
        }

        return DB::transaction(function () use ($operation, $data, $result, $batch, $hashes, $failures): Operation {
            $first = $batch && $result['replace_inventory'] === true;
            $projected = app(DirectOperation::class)->project($operation, $data, $result, 'fill-package-cache');
            if ($batch) {
                $this->inventory($operation, $hashes, $failures, $first);
            }

            return $projected;
        });
    }

    /** @param array<string,string> $hashes
     * @param  array<string,string>  $failures
     */
    private function inventory(Operation $operation, array $hashes, array $failures, bool $first): void
    {
        foreach ($failures as $key => $reason) {
            Log::warning('Task VM package cache artifact failed.', ['host_node_id' => $operation->node_id, 'archive_id' => $key, 'reason' => $reason]);
        }
        foreach (TaskVmInputs::snapshots($operation->input['snapshots']) as $snapshot) {
            $current = PackageCacheSnapshot::query()->lockForUpdate()->find($snapshot['project_id']);
            if (! $current instanceof PackageCacheSnapshot || $current->blobs !== $snapshot['blobs'] || $current->artifacts !== $snapshot['artifacts']) {
                continue;
            }
            $entries = PackageCacheEntry::query()->where('project_id', $current->project_id)->where('host_node_id', $operation->node_id);
            if ($first) {
                $entries->delete();
            } else {
                $entries->whereIn('key', [...array_keys($hashes), ...array_keys($failures)])->delete();
            }
            foreach ($snapshot['artifacts'] as $artifact) {
                if (isset($hashes[$artifact['key']])) {
                    PackageCacheEntry::query()->create(['project_id' => $current->project_id, 'host_node_id' => $operation->node_id,
                        ...Arr::only($artifact, ['manager', 'package', 'version', 'key']), 'integrity' => $hashes[$artifact['key']]]);
                }
            }
            $complete = true;
            foreach ($this->settings->hosts as $host) {
                $entries = PackageCacheEntry::query()->where('project_id', $current->project_id)->where('host_node_id', $host->nodeId)
                    ->get()->keyBy(static fn (PackageCacheEntry $entry): string => $entry->package.' '.$entry->version.' '.$entry->key);
                foreach ($snapshot['artifacts'] as $artifact) {
                    if (! $entries->has($artifact['package'].' '.$artifact['version'].' '.$artifact['key'])) {
                        $complete = false;
                        break 2;
                    }
                }
            }
            $current->update(['fill_pending' => ! $complete]);
        }
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.result_invalid', 'The cache result must contain bounded admitted archive hashes or failures.', 422);
    }
}
