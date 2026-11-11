<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Models\Instance;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Development projection storage. Call writes inside the Instance's locked transaction. */
final readonly class DevelopmentDeployRetries
{
    /** @param list<string> $commits
     * @return array<string, mixed>
     */
    public function read(Instance $instance, array $commits): array
    {
        $entries = DB::table('development_deploy_retries')->where('instance_id', $instance->id)
            ->whereIn('commit', $commits)->orderBy('commit')->get()->map(static fn (object $row): array => ['commit' => $row->commit, ...json_decode($row->state, true, flags: JSON_THROW_ON_ERROR)])->all();

        return [...$this->cursor($instance), 'commits' => $entries];
    }

    /** @return array<string, mixed> */
    public function cursor(Instance $instance): array
    {
        return $instance->auto_deploy_state['development_retry_cursor'] ?? ['manual_after' => 0, 'observed_through' => 0, 'unsettled' => []];
    }

    /** @param array<string, mixed> $ledger */
    public function publish(Instance $instance, array $ledger): void
    {
        if ($ledger['manual_after'] > $this->cursor($instance)['manual_after']) {
            DB::table('development_deploy_retries')->where('instance_id', $instance->id)->delete();
        }
        foreach ($ledger['commits'] as $entry) {
            $this->store($instance, $entry);
        }
        $instance->auto_deploy_state = [...($instance->auto_deploy_state ?? []),
            'development_retry_cursor' => array_diff_key($ledger, ['commits' => true])];
    }

    public function manual(Instance $instance, int $historyId): void
    {
        DB::table('development_deploy_retries')->where('instance_id', $instance->id)->delete();
        $instance->auto_deploy_state = [...($instance->auto_deploy_state ?? []), 'development_retry_cursor' => [
            ...$this->cursor($instance), 'manual_after' => $historyId, 'unsettled' => [],
        ]];
    }

    public function succeeded(Instance $instance, string $commit, int $operationId, int $historyId): void
    {
        $this->store($instance, ['commit' => $commit, 'attempts' => 0, 'last_failure_at' => null, 'stopped' => false,
            'evidence' => [], 'reset_operation_id' => $operationId, 'reset_after' => $historyId, 'reset_at' => now()->toIso8601String()]);
    }

    /** @param array<string, mixed> $entry */
    private function store(Instance $instance, array $entry): void
    {
        DB::table('development_deploy_retries')->updateOrInsert(['instance_id' => $instance->id, 'commit' => ValidatedData::string($entry['commit'])],
            ['state' => json_encode(array_diff_key($entry, ['commit' => true]), JSON_THROW_ON_ERROR)]);
    }
}
