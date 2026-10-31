<?php

declare(strict_types=1);

namespace App\Domain\Problems;

use App\Data\Doctor\DoctorIssueData;
use App\Data\Doctor\DoctorReportData;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Logs\LogRedactor;
use App\Domain\Shared\StoredInteger;
use App\Models\Activity;
use App\Models\ProblemCollectorState;
use App\Models\ProblemFingerprint;
use App\Models\Task;
use App\Support\ValidatedData;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records recurring Doctor, Activity, log, and assistance signals, and the release alerts that release commands push.
 * Each source commits its fingerprints and its cursor together.
 */
final readonly class ProblemCollector
{
    public function __construct(
        private ProblemEvidence $evidence,
        private ProblemSuppression $suppression,
        private ProblemRecovery $recovery,
        private LogRedactor $redactor,
    ) {}

    /** Safe state read. Swamp owns classification, ordering and source selection.
     * @return array<string,mixed>
     */
    public function input(): array
    {
        $cursor = ProblemCollectorState::query()->orderBy('id')->value('activity_cursor');
        $activities = $cursor === null ? [] : Activity::query()->where('id', '>', $cursor)->orderBy('id')->limit(500)->get(['id', 'command', 'error_code', 'exit_code', 'request_id', 'created_at', 'properties'])->map(function (Activity $row): array {
            $message = $row->properties?->get('error_message');

            return [
                'id' => $row->id, 'command' => $row->command, 'error_code' => $row->error_code, 'exit_code' => $row->exit_code,
                'request_id' => $row->request_id, 'created_at' => $row->created_at?->toISOString(),
                'path' => $row->properties?->get('path'), 'message' => is_string($message) ? $this->redactor->redact($message, []) : '',
            ];
        })->all();

        return ['activity_cursor' => $cursor, 'activity_max' => StoredInteger::fromOrZero(Activity::query()->max('id')), 'activities' => $activities,
            'assistance' => Task::query()->withoutGlobalScope('subtask')->where('assistance_requested', true)->orderBy('id')->get(['id', 'assistance_reason'])->map(fn (Task $task): array => [
                'id' => $task->id, 'reason' => $task->assistance_reason === null ? null : $this->redactor->redact($task->assistance_reason, []),
            ])->all(),
            'stored_assistance_ids' => array_values(array_unique(ProblemFingerprint::query()->where('source', ProblemSource::Assist)->get(['evidence'])->flatMap(fn (ProblemFingerprint $row): array => $this->evidence->ints($row->evidence['assistance_task_ids'] ?? null))->all()))];
    }

    /** Applies native-selected fingerprints and cursor under the owning state lock.
     * @param  array<string,mixed>  $native
     */
    public function recordSignals(array $native): void
    {
        DB::transaction(function () use ($native): void {
            $state = $this->lockedState();
            if ($state->activity_cursor === ($native['activity_cursor_before'] ?? null)) {
                foreach (Arr::array($native, 'activities', []) as $value) {
                    $signal = ValidatedData::object($value);
                    $this->record($this->fingerprint(ProblemSource::Activity, Arr::string($signal, 'key')), ProblemSource::Activity, Arr::array($signal, 'observation'), Carbon::parse(Arr::string($signal, 'seen_at')));
                }
                $state->activity_cursor = Arr::integer($native, 'activity_cursor_after');
                $state->save();
            }
            $this->pruneAssistance($this->evidence->ints($native['open_assistance_ids'] ?? null));
            foreach (Arr::array($native, 'assistance', []) as $value) {
                $signal = ValidatedData::object($value);
                $fingerprint = $this->fingerprint(ProblemSource::Assist, Arr::string($signal, 'key'));
                if (! $this->assistanceIsFull($fingerprint)) {
                    $this->record($fingerprint, ProblemSource::Assist, Arr::array($signal, 'observation'));
                }
            }
        });
    }

    /**
     * Records one pushed release alert under `release|{key}`. Stored is false when the fingerprint is suppressed.
     *
     * @param  array<string, mixed>  $observation
     * @return array{fingerprint: string, stored: bool}
     */
    public function recordRelease(string $key, array $observation): array
    {
        $fingerprint = $this->fingerprint(ProblemSource::Release, $key);

        if ($this->suppression->suppressesSignal($fingerprint, $observation)) {
            return ['fingerprint' => $fingerprint, 'stored' => false];
        }

        DB::transaction(fn () => $this->record($fingerprint, ProblemSource::Release, $observation));

        return ['fingerprint' => $fingerprint, 'stored' => true];
    }

    public function recordDoctor(DoctorReportData $report, Carbon $startedAt): void
    {
        $issues = $this->doctorIssues($report);
        DB::transaction(function () use ($issues, $report, $startedAt): void {
            foreach ($issues as $issue) {
                $this->record($issue['fingerprint'], ProblemSource::Doctor, [
                    'expected' => $issue['expected'],
                    'observed' => $issue['observed'],
                    'summary' => $issue['summary'],
                    'doctor_node' => $issue['doctor_node'],
                    'doctor_family' => $issue['doctor_family'],
                    'doctor_code' => $issue['doctor_code'],
                    'doctor_resource' => $issue['doctor_resource'],
                    'doctor_resource_present' => $this->recovery->resourceExists($issue['doctor_family'], $issue['doctor_resource'], (int) $issue['doctor_node']),
                    'doctor_observed_at' => $startedAt->toISOString(),
                ]);
            }
            $this->recovery->doctor($report, $startedAt);
        });
    }

    /**
     * @param  array<string, mixed>  $native
     * @param  array<string, mixed>|null  $expectedCursor
     */
    public function recordLogs(array $native, ?array $expectedCursor = null): void
    {
        DB::transaction(function () use ($native, $expectedCursor): void {
            $state = $this->lockedState();
            $cursor = $state->log_path === null ? null : ['path' => $state->log_path, 'inode' => $state->log_inode, 'offset' => $state->log_offset];
            if ($cursor !== $expectedCursor) {
                return;
            }
            foreach (Arr::array($native, 'signals', []) as $value) {
                $signal = ValidatedData::object($value);
                $requestId = ValidatedData::nullableString($signal['request_id'] ?? null);
                $frame = Arr::string($signal, 'frame');
                $observation = ['request_ids' => $requestId === null || $requestId === '' ? [] : [$requestId]];
                $sourcePath = $this->suppression->logFramePath($frame);
                if ($sourcePath !== null) {
                    $observation['source_path'] = $sourcePath;
                }
                $this->record($this->fingerprint(ProblemSource::Log, Arr::string($signal, 'exception_class').'|'.$frame), ProblemSource::Log, $observation, $this->logRecordedAt(Arr::string($signal, 'recorded_at')));
            }
            if (isset($native['cursor'])) {
                $next = ValidatedData::object($native['cursor']);
                $state->log_path = Arr::string($next, 'path');
                $state->log_inode = Arr::integer($next, 'inode');
                $state->log_offset = Arr::integer($next, 'offset');
                $state->save();
            }
        });
    }

    /** @return list<array{fingerprint: string, expected: bool|string|null, observed: bool|string|null, summary: string, doctor_node: string, doctor_family: string, doctor_code: string, doctor_resource: string|null}> */
    private function doctorIssues(DoctorReportData $report): array
    {
        $issues = [];

        foreach ($report->nodes as $node) {
            foreach ($node->families as $family) {
                foreach ($family->issues as $issue) {
                    // Doctor health ignores informational findings, so they are not problems either.
                    if ($issue->kind === DoctorIssueKind::Informational) {
                        continue;
                    }

                    $issues[] = [
                        'fingerprint' => $this->doctorFingerprint($issue),
                        'expected' => $issue->expected,
                        'observed' => $issue->observed,
                        'summary' => $issue->summary,
                        'doctor_node' => (string) $node->nodeId,
                        'doctor_family' => $family->family->value,
                        'doctor_code' => $issue->code,
                        'doctor_resource' => $issue->resourceId === null ? null : (string) $issue->resourceId,
                    ];
                }
            }
        }

        usort($issues, static fn (array $left, array $right): int => strcmp($left['fingerprint'], $right['fingerprint']));

        return $issues;
    }

    private function doctorFingerprint(DoctorIssueData $issue): string
    {
        $resource = $issue->resourceId;
        $segment = $resource === null || $resource === '' ? 'none' : (string) $resource;

        return $this->fingerprint(ProblemSource::Doctor, $issue->code.'|'.$issue->resourceType.'|'.$segment);
    }

    /** @param list<int> $stillOpen */
    private function pruneAssistance(array $stillOpen): void
    {
        $open = array_fill_keys($stillOpen, true);
        $rows = ProblemFingerprint::query()->where('source', ProblemSource::Assist)->lockForUpdate()->get();

        foreach ($rows as $row) {
            $evidence = $row->evidence;
            $ids = $this->evidence->ints($evidence['assistance_task_ids'] ?? null);
            $kept = array_values(array_filter($ids, static fn (int $id): bool => isset($open[$id])));

            if ($kept === $ids) {
                continue;
            }

            $evidence['assistance_task_ids'] = $kept;
            $row->evidence = $evidence;
            $row->save();
        }
    }

    private function assistanceIsFull(string $fingerprint): bool
    {
        $row = ProblemFingerprint::query()->where('fingerprint', $fingerprint)->first();

        if (! $row instanceof ProblemFingerprint) {
            return false;
        }

        return count($this->evidence->ints($row->evidence['assistance_task_ids'] ?? null)) >= 200;
    }

    private function fingerprint(ProblemSource $source, string $rest): string
    {
        $key = $source->value.'|'.$rest;

        if (mb_strlen($key) <= 255) {
            return $key;
        }

        return $source->value.'#'.substr(hash('sha256', $key), 0, 12);
    }

    /**
     * The bracketed log header is written in the Gateway application timezone.
     */
    private function logRecordedAt(string $recordedAt): Carbon
    {
        $timezone = config('app.timezone');

        return Carbon::parse($recordedAt, is_string($timezone) && $timezone !== '' ? $timezone : 'UTC');
    }

    /** @param array<string, mixed> $observation */
    private function record(string $fingerprint, ProblemSource $source, array $observation, ?CarbonInterface $seenAt = null): void
    {
        if ($this->suppression->suppressesSignal($fingerprint, $observation)) {
            return;
        }

        $seenAt = Carbon::parse($seenAt ?? now())->utc();
        $row = ProblemFingerprint::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();

        if (! $row instanceof ProblemFingerprint) {
            $row = new ProblemFingerprint([
                'fingerprint' => $fingerprint,
                'source' => $source,
                'occurrences' => 0,
                'evidence' => [],
            ]);
        }

        $evidence = $row->evidence;
        if (isset($evidence['auto_closed'])) {
            $row->task_group_id = null;
            unset($evidence['generated_group_hash'], $evidence['auto_closed']);
        }
        unset($evidence['resolved_at'], $evidence['resolution']);
        $occurrences = $row->occurrences;
        $settled = $this->evidence->legacyEpisode($evidence, $source);

        if ($settled !== null) {
            $evidence = $settled['evidence'];
            $occurrences = $settled['occurrences'];

            if ($settled['reset_seen']) {
                $row->first_seen = null;
                $row->last_seen = null;
            }
        }

        $times = $this->observationTimes($evidence);
        $counts = $this->observationCounts($evidence, count($times));
        $blocks = $this->countedBlocks($evidence, $times);
        $block = intdiv($seenAt->getTimestamp(), 300);

        if (! in_array($block, $blocks, true)) {
            $blocks[] = $block;
            $occurrences++;
            $times[] = $seenAt->format('Y-m-d\TH:i:s.u\Z');
            $counts[] = 1;
            [$times, $counts] = $this->trimSample($times, $counts);
        } else {
            $index = $this->sampleIndex($times, $block);

            if ($index !== null) {
                $counts[$index]++;
            }
        }

        $row->source = $source;
        $row->occurrences = $occurrences;
        $row->first_seen ??= $seenAt;
        $row->last_seen = $seenAt;
        $stored = $this->evidence->apply($evidence, $observation);
        $stored['observation_times'] = $times;
        $stored['observation_counts'] = $counts;
        $stored['counted_blocks'] = $blocks;
        $row->evidence = $stored;
        $row->save();
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<string>
     */
    private function observationTimes(array $evidence): array
    {
        $times = $evidence['observation_times'] ?? null;

        if (! is_array($times)) {
            return [];
        }

        $parsed = [];

        foreach ($times as $time) {
            if (is_string($time) && $time !== '') {
                $parsed[] = $time;
            }
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<int>
     */
    private function observationCounts(array $evidence, int $windows): array
    {
        $counts = array_slice($this->evidence->ints($evidence['observation_counts'] ?? null), 0, $windows);

        while (count($counts) < $windows) {
            $counts[] = 1;
        }

        return $counts;
    }

    /**
     * The display sample keeps the newest 20 windows. counted_blocks keeps every window in the episode,
     * including one the sample has dropped, so a late signal in that block is not counted again.
     *
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $times
     * @return list<int>
     */
    private function countedBlocks(array $evidence, array $times): array
    {
        if (array_key_exists('counted_blocks', $evidence)) {
            return $this->evidence->ints($evidence['counted_blocks']);
        }

        $blocks = [];

        foreach ($times as $time) {
            $stamp = $this->unixTime($time);

            if ($stamp === null) {
                continue;
            }

            $block = intdiv($stamp, 300);

            if (! in_array($block, $blocks, true)) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /** @param list<string> $times */
    private function sampleIndex(array $times, int $block): ?int
    {
        foreach ($times as $index => $time) {
            $stamp = $this->unixTime($time);

            if ($stamp !== null && intdiv($stamp, 300) === $block) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $times
     * @param  list<int>  $counts
     * @return array{0: list<string>, 1: list<int>}
     */
    private function trimSample(array $times, array $counts): array
    {
        $order = array_keys($times);
        usort($order, fn (int $left, int $right): int => ($this->unixTime($times[$left]) ?? 0) <=> ($this->unixTime($times[$right]) ?? 0));
        $trimmedTimes = [];
        $trimmedCounts = [];

        foreach (array_slice($order, -20) as $index) {
            $trimmedTimes[] = $times[$index];
            $trimmedCounts[] = $counts[$index] ?? 1;
        }

        return [$trimmedTimes, $trimmedCounts];
    }

    private function unixTime(string $time): ?int
    {
        try {
            return Carbon::parse($time)->utc()->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    private function lockedState(): ProblemCollectorState
    {
        $state = ProblemCollectorState::query()->lockForUpdate()->orderBy('id')->first();

        if ($state instanceof ProblemCollectorState) {
            return $state;
        }

        return ProblemCollectorState::query()->create([]);
    }
}
