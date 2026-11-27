<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationLateResult;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationTimeout;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AptRepository;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * The end of `tool.apt_repository_add` and `tool.apt_repository_remove` (docs/reference/tools.md#apt-repositories). The run's report names
 * the repository's outcome: an add that published the key, preferences and source makes it ready, a removal that cleared its files deletes
 * it. Any other end leaves it failed with its code, and running the command again is the recovery.
 */
final readonly class AptRepositoryProjection implements DirectOperationLateResult, DirectOperationProjection, DirectOperationTimeout
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['intent'] ?? []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $outcome = $this->outcome($operation, ($data['status'] ?? null) === 'succeeded', ValidatedData::object($data['result'] ?? []));

        return DB::transaction(function () use ($operation, $data, $outcome): Operation {
            $locked = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($locked->status->terminal()) {
                return $locked;
            }
            AptRepositoryOutcomes::record(ValidatedData::integer($locked->node_id), $locked->id, [$outcome]);
            $failed = $outcome['status'] === 'failed';

            return $this->direct->project($locked, [...$data, 'status' => $failed ? 'failed' : 'succeeded', 'error_code' => $outcome['error_code']],
                ['repository' => $outcome['name'], 'status' => $outcome['status'], 'error_code' => $outcome['error_code']]);
        });
    }

    public function late(Operation $operation, string $status, array $result, array $run): void
    {
        AptRepositoryOutcomes::record(ValidatedData::integer($operation->node_id), $operation->id, [$this->outcome($operation, $status === 'succeeded', $result)]);
    }

    /** Nothing reached the Node: the repository is as it was before this request, its definition and deciding Operation included. */
    public function neverRan(Operation $operation): void
    {
        $record = AptRepository::query()->whereKey($operation->input['repository_id'] ?? null)->where('operation_id', $operation->id)->lockForUpdate()->first();
        $previous = $operation->input['previous'] ?? null;
        if ($record === null) {
            return;
        }
        if (! is_array($previous)) {
            $record->delete();

            return;
        }
        $record->update(['status' => AptRepositoryStatus::from(ValidatedData::string($previous['status'])), 'error_code' => ValidatedData::nullableString($previous['error_code'] ?? null),
            'definition' => ValidatedData::object($previous['definition'] ?? $record->definition), 'operation_id' => is_int($previous['operation_id'] ?? null) ? $previous['operation_id'] : null]);
    }

    /** The run may still be changing the Node: the repository is failed until the command runs again. */
    public function timedOut(Operation $operation): void
    {
        AptRepository::query()->whereKey($operation->input['repository_id'] ?? null)->where('operation_id', $operation->id)
            ->update(['status' => AptRepositoryStatus::Failed, 'error_code' => $operation->error_code]);
    }

    /**
     * The repository's outcome by the run's report: the one it names for this repository when the whole run succeeded and cleaned up, else
     * failed with the code it named or the command's generic one.
     *
     * @param  array<string, mixed>  $result
     * @return array{name: string, status: string, error_code: ?string}
     */
    private function outcome(Operation $operation, bool $succeeded, array $result): array
    {
        $name = ValidatedData::string($operation->input['name'] ?? null);
        $removal = $operation->kind === 'tool.apt_repository_remove';
        try {
            $reported = array_column(AptRepositoryOutcomes::validate($result['repositories'] ?? []), null, 'name')[$name] ?? null;
        } catch (ResourceOperationException) {
            $reported = null;
        }
        $done = $succeeded && ($result['succeeded'] ?? null) === true && ($result['cleanup_complete'] ?? null) === true
            && ($reported['status'] ?? null) === ($removal ? 'removed' : 'ready');

        return $done ? ['name' => $name, 'status' => $removal ? 'removed' : 'ready', 'error_code' => null]
            : ['name' => $name, 'status' => 'failed', 'error_code' => $reported['error_code'] ?? ($removal ? 'tool.repository_remove_failed' : 'tool.repository_failed')];
    }
}
