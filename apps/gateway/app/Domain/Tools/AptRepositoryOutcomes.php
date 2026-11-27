<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Shared\ResourceOperationException;
use App\Models\AptRepository;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Validator;

/**
 * The outcome of each apt repository a run published or removed, by the run's own report: an add that published the key, preferences and
 * source makes the repository ready, a failed one leaves it failed with its code, and a removal that cleared its files deletes the record
 * (docs/reference/tools.md#apt-repositories). Only the newest Operation admitted to publish or remove a repository decides it: an older run read
 * late changes nothing a newer add, removal or convergence may have changed since.
 */
final class AptRepositoryOutcomes
{
    public const array Codes = ['tool.repository_key_mismatch', 'tool.repository_unavailable', 'tool.repository_failed', 'tool.repository_remove_failed'];

    /** @return list<array{name: string, status: string, error_code: ?string}> */
    public static function validate(mixed $raw): array
    {
        $valid = Validator::make(['repositories' => $raw ?? []], [
            'repositories' => ['array', 'list', 'max:1000'],
            'repositories.*' => ['required', 'array:name,status,error_code'],
            'repositories.*.name' => ['required', 'string', 'max:63', 'regex:/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/D', 'distinct:strict'],
            'repositories.*.status' => ['required', 'in:ready,failed,removed'],
            'repositories.*.error_code' => ['present', 'nullable', 'in:'.implode(',', self::Codes)],
        ]);
        if ($valid->fails()) {
            throw new ResourceOperationException('operation.result_invalid', 'The apt repository outcomes are invalid.', 422);
        }
        $outcomes = [];
        foreach (is_array($raw) ? $raw : [] as $value) {
            $outcome = ValidatedData::object($value);
            if (($outcome['status'] === 'failed') !== is_string($outcome['error_code'])) {
                throw new ResourceOperationException('operation.result_invalid', 'A failed apt repository outcome needs its code.', 422);
            }
            $outcomes[] = ['name' => ValidatedData::string($outcome['name']), 'status' => ValidatedData::string($outcome['status']),
                'error_code' => ValidatedData::nullableString($outcome['error_code'])];
        }

        return $outcomes;
    }

    /**
     * Makes an admitted Operation the one whose outcome decides each repository it publishes or removes.
     *
     * @param  list<mixed>  $ids
     */
    public static function claim(array $ids, int $operationId): void
    {
        AptRepository::query()->whereKey($ids)->update(['operation_id' => $operationId]);
    }

    /** @param list<array{name: string, status: string, error_code: ?string}> $outcomes */
    public static function record(int $nodeId, int $operationId, array $outcomes): void
    {
        foreach ($outcomes as $outcome) {
            $record = AptRepository::query()->where('node_id', $nodeId)->where('name', $outcome['name'])->lockForUpdate()->first();
            if ($record === null || $record->operation_id !== $operationId) {
                continue;
            }
            match ($outcome['status']) {
                'ready' => $record->update(['status' => AptRepositoryStatus::Ready, 'error_code' => null]),
                'removed' => $record->tools()->exists() ? $record->update(['status' => AptRepositoryStatus::Failed, 'error_code' => 'tool.repository_remove_failed']) : $record->delete(),
                default => $record->update(['status' => AptRepositoryStatus::Failed, 'error_code' => $outcome['error_code']]),
            };
        }
    }
}
