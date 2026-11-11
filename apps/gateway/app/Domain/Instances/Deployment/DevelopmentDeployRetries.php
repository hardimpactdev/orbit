<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\OperationStatus;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Development projection storage. Call writes inside the Instance's locked transaction.
 *
 * @phpstan-type RetryEvidence array{key: ?string, deployment_id: ?int, operation_id?: int, finished_at?: string}
 * @phpstan-type RetryEntry array{commit: string, attempts: int, last_failure_at: ?string, stopped: bool, evidence: list<RetryEvidence>, reset_operation_id: int, reset_after: int, reset_at: ?string}
 * @phpstan-type RetryCursor array{manual_after: int, observed_through: int, unsettled: list<int>}
 * @phpstan-type RetryLedger array{manual_after: int, observed_through: int, unsettled: list<int>, commits: list<RetryEntry>}
 */
final readonly class DevelopmentDeployRetries
{
    /** @param list<string> $commits
     * @return RetryLedger
     */
    public function read(Instance $instance, array $commits): array
    {
        $entries = DB::table('development_deploy_retries')->where('instance_id', $instance->id)
            ->whereIn('commit', $commits)->orderBy('commit')->get()->map(static fn (object $row): array => self::decode(
                ValidatedData::string($row->commit),
                ValidatedData::object(json_decode(ValidatedData::string($row->state), true, flags: JSON_THROW_ON_ERROR)),
            ))->all();

        return [...$this->cursor($instance), 'commits' => array_values($entries)];
    }

    /** @return RetryCursor */
    public function cursor(Instance $instance): array
    {
        $cursor = ValidatedData::object($instance->auto_deploy_state['development_retry_cursor'] ?? []);

        return ['manual_after' => ValidatedData::integer($cursor['manual_after'] ?? 0),
            'observed_through' => ValidatedData::integer($cursor['observed_through'] ?? 0),
            'unsettled' => ValidatedData::integerList($cursor['unsettled'] ?? [])];
    }

    public function manual(Instance $instance, int $operationId): void
    {
        DB::table('development_deploy_retries')->where('instance_id', $instance->id)->delete();
        $instance->auto_deploy_state = [...($instance->auto_deploy_state ?? []), 'development_retry_cursor' => [
            ...$this->cursor($instance), 'manual_after' => $operationId, 'unsettled' => [],
        ]];
    }

    public function succeeded(Instance $instance, string $commit, int $operationId, int $historyId): void
    {
        $previous = $this->read($instance, [$commit])['commits'][0] ?? [];
        $later = array_values(array_filter($previous['evidence'] ?? [], static fn (array $e): bool => ($e['operation_id'] ?? 0) > $operationId));
        $last = null;
        foreach ($later as $evidence) {
            $at = ValidatedData::string($evidence['finished_at'] ?? null);
            if ($last === null || Carbon::parse($at)->isAfter(Carbon::parse($last))) {
                $last = $at;
            }
        }
        $this->store($instance, ['commit' => $commit, 'attempts' => count($later),
            'last_failure_at' => $last, 'stopped' => count($later) >= 3,
            'evidence' => $later, 'reset_operation_id' => $operationId, 'reset_after' => $historyId, 'reset_at' => now()->toIso8601String()]);
    }

    /** Settle the immutable admission pin, including failures without a history row. Replays never add an attempt. */
    public function settled(Operation $operation): void
    {
        $variant = ValidatedData::object($operation->input['deployment'] ?? [])['variant'] ?? null;
        if ($operation->kind !== 'instance.deploy' || ! in_array($variant, ['container', 'development'], true) || ! $operation->status->terminal()) {
            return;
        }
        $commit = InstanceDeploymentIntent::commit($operation);
        if ($commit === null) {
            return;
        }
        DB::transaction(function () use ($operation, $commit): void {
            $instance = Instance::query()->lockForUpdate()->findOrFail($operation->target_id);
            $cursor = $this->cursor($instance);
            if ($operation->id <= $cursor['manual_after']) {
                return;
            }
            if (! in_array($operation->triggered_by, AutomaticDeployHistory::Triggers, true)) {
                // Manual admission already establishes its reset boundary, even while it is running.
                return;
            }
            $entry = $this->read($instance, [$commit])['commits'][0] ?? [
                'commit' => $commit, 'attempts' => 0, 'last_failure_at' => null, 'stopped' => false, 'evidence' => [],
                'reset_operation_id' => 0, 'reset_after' => 0, 'reset_at' => null,
            ];
            if ($operation->id <= $entry['reset_operation_id']) {
                return;
            }
            if ($operation->status === OperationStatus::Succeeded) {
                $this->succeeded($instance, $commit, $operation->id, ValidatedData::integer($operation->result['deployment_id'] ?? 0));

                return;
            }
            if (in_array($operation->error_code, ['deployment.paused', 'operation.admission_fenced'], true)) {
                return;
            }
            $key = 'operation:'.$operation->id;
            if ($entry['stopped'] || array_any($entry['evidence'], static fn (array $e): bool => $e['key'] === $key)) {
                return;
            }
            $at = ($operation->finished_at ?? now())->toIso8601String();
            $entry['evidence'][] = ['operation_id' => $operation->id, 'finished_at' => $at, 'key' => $key, 'deployment_id' => (($id = $operation->result['deployment_id'] ?? $operation->state['deployment_id'] ?? null) === null ? null : ValidatedData::integer($id))];
            $entry['attempts']++;
            $entry['stopped'] = $entry['attempts'] >= 3;
            if ($entry['last_failure_at'] === null || Carbon::parse($at)->isAfter(Carbon::parse($entry['last_failure_at']))) {
                $entry['last_failure_at'] = $at;
            }
            $this->store($instance, $entry);
        });
    }

    /** Reconcile retained receipts once, retaining active IDs for later settlement. Reads do not grow with stopped SHA count. */
    public function observe(Instance $instance): void
    {
        DB::transaction(function () use ($instance): void {
            $locked = Instance::query()->lockForUpdate()->findOrFail($instance->id);
            $cursor = $this->cursor($locked);
            $through = $cursor['observed_through'];
            $unsettled = [];
            Operation::query()->where('kind', 'instance.deploy')->where('target_type', 'instance')->where('target_id', $instance->id)
                ->where(static fn ($query) => $query->where('id', '>', $cursor['observed_through'])->orWhereIn('id', $cursor['unsettled']))
                ->orderBy('id')->chunkById(50, function ($operations) use (&$through, &$unsettled): void {
                    foreach ($operations as $operation) {
                        $through = max($through, $operation->id);
                        if ($operation->status->terminal()) {
                            $this->settled($operation);
                        } else {
                            $unsettled[] = $operation->id;
                        }
                    }
                });
            $locked->refresh();
            $next = [...$this->cursor($locked), 'observed_through' => $through, 'unsettled' => $unsettled];
            if ($this->cursor($locked) !== $next) {
                $locked->update(['auto_deploy_state' => [...($locked->auto_deploy_state ?? []), 'development_retry_cursor' => $next]]);
            }
        });
    }

    /** @param array<string, mixed> $state
     * @return RetryEntry
     */
    private static function decode(string $commit, array $state): array
    {
        $stopped = $state['stopped'] ?? null;
        $values = $state['evidence'] ?? null;
        if (! is_bool($stopped) || ! is_array($values) || ! array_is_list($values)) {
            throw new \LogicException('The stored development retry state is invalid.');
        }
        $evidence = array_map(static function (mixed $value): array {
            $entry = ValidatedData::object($value);

            return ['key' => ValidatedData::nullableString($entry['key'] ?? null),
                'deployment_id' => ($entry['deployment_id'] ?? null) === null ? null : ValidatedData::integer($entry['deployment_id']),
                ...array_key_exists('operation_id', $entry) ? ['operation_id' => ValidatedData::integer($entry['operation_id']),
                    'finished_at' => ValidatedData::string($entry['finished_at'] ?? null)] : [],
            ];
        }, $values);

        return ['commit' => $commit, 'attempts' => ValidatedData::integer($state['attempts']),
            'last_failure_at' => ValidatedData::nullableString($state['last_failure_at']), 'stopped' => $stopped, 'evidence' => $evidence,
            'reset_operation_id' => ValidatedData::integer($state['reset_operation_id'] ?? 0),
            'reset_after' => ValidatedData::integer($state['reset_after'] ?? 0), 'reset_at' => ValidatedData::nullableString($state['reset_at'] ?? null)];
    }

    /** @param array<string, mixed> $entry */
    private function store(Instance $instance, array $entry): void
    {
        DB::table('development_deploy_retries')->updateOrInsert(['instance_id' => $instance->id, 'commit' => ValidatedData::string($entry['commit'])],
            ['state' => json_encode(array_diff_key($entry, ['commit' => true]), JSON_THROW_ON_ERROR)]);
    }
}
