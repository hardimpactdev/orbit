<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
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

    public function input(Operation $operation): array
    {
        $input = $operation->input;
        if ($operation->kind !== 'task_vm.fill_package_cache' || $operation->target_type !== 'task_vm_cache'
            || $operation->node_id !== $operation->target_id || ($input['host_node_id'] ?? null) !== $operation->node_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Cache intent requires its exact admitted host.', 422);
        }

        $this->settings->host(ValidatedData::integer($operation->node_id));

        return [...Arr::only($input, ['artifacts', 'max_bytes']), 'conflicts' => (object) ValidatedData::stringMap($input['conflicts'])];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $result = ValidatedData::object($data['result'] ?? []);
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($data['status'] === OperationStatus::Running->value && $result !== []) {
                $hashes = array_combine(ValidatedData::stringList($result['archive_ids']), ValidatedData::stringList($result['archive_digests']));
                $failures = array_combine(ValidatedData::stringList($result['failed_ids']),
                    array_map(new CommandActivityInputSanitizer()->redactText(...), ValidatedData::stringList($result['failure_reasons'])));
                $this->inventory($operation, $hashes, $failures, $result['replace_inventory'] === true);
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
}
