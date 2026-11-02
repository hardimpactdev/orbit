<?php

declare(strict_types=1);

namespace App\Domain\SwampDatastore;

use App\Domain\Operations\ScheduledResultProjection;
use App\Domain\Problems\ProblemCollector;
use App\Domain\Settings\SettingScopeType;
use App\Models\Setting;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Raises a swamp problem when the swamp role's 15-minute datastore watch on Node 209 sees serve's datastore or CPU past
 * a bound, and resolves it once a later fire sees it clear. Large auto-definition directories drove serve CPU past 100%
 * (https://swamp-club.com/lab/4280).
 */
final readonly class SwampDatastoreWatch implements ScheduledResultProjection
{
    public const string Workflow = '11bb5e01-eb16-4425-930e-2b7e2a8bd100';

    public const string StateKey = 'swamp.datastore-watch';

    public const int AutoDefinitionsPerType = 2000;

    public const int Outputs = 50000;

    public const int ServeCpuPercent = 50;

    public function __construct(private ProblemCollector $problems) {}

    public function activate(): void {}

    public function applyScheduled(string $workflowId, array $run, array $result): void
    {
        if ($workflowId !== self::Workflow) {
            throw new LogicException('Unknown Swamp datastore watch schedule.');
        }
        $runId = Arr::string($run, 'id');
        $startedAt = Carbon::parse(Arr::string($run, 'startedAt'));
        DB::transaction(function () use ($run, $result, $runId, $startedAt): void {
            $record = $this->state();
            $previous = ValidatedData::object(json_decode($record->value ?? '{}', true, 512, JSON_THROW_ON_ERROR));
            // Durable replay marker: a replayed or older fire changes nothing.
            if (($previous['run_id'] ?? null) === $runId || (is_string($previous['started_at'] ?? null)
                && Carbon::parse($previous['started_at'])->greaterThanOrEqualTo($startedAt))) {
                return;
            }
            $this->problems->recordSwamp(($run['status'] ?? null) === 'succeeded'
                ? $this->findings($runId, $result) : $this->failed($runId, $run), $startedAt);
            $record->value = json_encode(['run_id' => $runId, 'started_at' => $startedAt->toISOString()], JSON_THROW_ON_ERROR);
            $record->saveQuietly();
        });
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string, array<string,mixed>>
     */
    private function findings(string $runId, array $result): array
    {
        $findings = [];
        foreach (array_slice(is_array($result['auto_definitions'] ?? null) ? $result['auto_definitions'] : [], 0, 256, true) as $type => $count) {
            if (is_string($type) && preg_match('/^[@a-z0-9_.\/-]{1,128}$/', $type) === 1 && is_int($count) && $count > self::AutoDefinitionsPerType) {
                $findings['datastore|auto_definitions|'.$type] = $this->finding($runId, "Swamp serve holds {$count} auto-definitions of type {$type}",
                    "Swamp serve on Node 209 holds {$count} auto-definitions of type {$type}, above the bound of ".self::AutoDefinitionsPerType
                    .'. A workflow probably names a behavior per run; serve scans the whole directory on each lookup by id.', ".swamp/auto-definitions/{$type}", $count, self::AutoDefinitionsPerType);
            }
        }
        $outputs = $result['outputs'] ?? null;
        if (is_int($outputs) && $outputs > self::Outputs) {
            $findings['datastore|outputs'] = $this->finding($runId, "Swamp serve holds {$outputs} outputs",
                "Swamp serve on Node 209 holds {$outputs} output records, above the bound of ".self::Outputs.'.', '.swamp/outputs', $outputs, self::Outputs);
        }
        $cpu = $result['serve_cpu_percent'] ?? null;
        if ((is_int($cpu) || is_float($cpu)) && $cpu > self::ServeCpuPercent) {
            $findings['serve|cpu'] = $this->finding($runId, "Swamp serve uses {$cpu}% CPU",
                "Swamp serve on Node 209 used {$cpu}% CPU over the watch's sample, above the bound of ".self::ServeCpuPercent.'%.', 'orbit-swamp-serve.service', (int) round($cpu), self::ServeCpuPercent);
        }

        return $findings;
    }

    /**
     * A failed fire leaves the datastore unwatched.
     *
     * @param  array<string,mixed>  $run
     * @return array<string, array<string,mixed>>
     */
    private function failed(string $runId, array $run): array
    {
        $status = is_string($run['status'] ?? null) ? $run['status'] : 'unknown';

        return ['watch|failed' => $this->finding($runId, 'The Swamp datastore watch failed',
            "The Swamp datastore watch run {$runId} ended {$status}; serve's datastore and CPU are unwatched.", 'swamp-datastore-watch', null, null)];
    }

    /** @return array<string,mixed> */
    private function finding(string $runId, string $title, string $summary, string $entry, ?int $observed, ?int $bound): array
    {
        return ['swamp_title' => $title, 'summary' => $summary." Run {$runId}.", 'swamp_entry' => $entry, 'observed' => $observed, 'expected' => $bound];
    }

    private function state(): Setting
    {
        return Setting::withoutEvents(fn (): Setting => Setting::query()->where('scope_type', SettingScopeType::Gateway)
            ->where('scope_id', 0)->where('key', self::StateKey)->lockForUpdate()->firstOrCreate([
                'scope_type' => SettingScopeType::Gateway, 'scope_id' => 0, 'key' => self::StateKey,
            ], ['value' => '{}', 'is_secret' => false]));
    }
}
