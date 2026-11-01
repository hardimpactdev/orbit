<?php

declare(strict_types=1);

namespace App\Domain\Observation;

use App\Actions\Doctor\StartDoctorAction;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorProjection;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Operations\ScheduledResultProjection;
use App\Domain\Problems\ProblemCollector;
use App\Infrastructure\Doctor\DoctorExecutionInput;
use App\Infrastructure\Gateway\GatewayHorizon;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Jobs\Observation\PublishObservationContext;
use App\Models\Node;
use App\Models\ProblemCollectorState;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Public intent publication and terminal projection; no probes, timers or host execution. */
final readonly class ScheduledObservation implements ScheduledResultProjection
{
    public function activate(): void
    {
        $this->invalidate(bootstrap: true);
    }

    public function invalidate(bool $expectations = true, bool $bootstrap = false): void
    {
        if (! is_string(config('swamp.run_token')) || config('swamp.run_token') === '') {
            return;
        }
        DB::transaction(function () use ($expectations, $bootstrap): void {
            $state = $bootstrap ? $this->state() : ProblemCollectorState::query()->lockForUpdate()->orderBy('id')->first();
            if ($state === null || (! $bootstrap && $state->observation === null)) {
                return;
            }
            if ($expectations) {
                $state->observation = [...($state->observation ?? []), 'dirty' => ['fleet' => true, 'horizon' => true]];
                $state->saveQuietly();
            }
            DB::afterCommit(fn () => $this->notify($expectations ? null : 'fleet'));
        });
    }

    public function publish(?string $only = null, bool $force = false): void
    {
        $contexts = DB::transaction(function () use ($only, $force): array {
            $state = $this->state();
            $stored = $state->observation ?? [];
            $revision = ValidatedData::integer($stored['revision'] ?? 0) + 1;
            $contexts = [];
            $channels = $only === null ? ['fleet', 'horizon'] : [$only];
            foreach ($channels as $channel) {
                $gateway = app(NodeAccessAuthorizer::class)->activeGatewayId();
                $nodes = Node::query()->when($channel === 'horizon', fn ($query) => $query->whereKey($gateway))->orderBy('name')->orderBy('id')->get();
                [$nodeId, $input] = app(StartDoctorAction::class)->capture(array_values($nodes->all()), $channel === 'horizon' ? [DoctorFamily::Process] : [], true);
                // Horizon ticks only inspect their registered runtime. Fleet owns log/activity cursors.
                if ($channel === 'horizon') {
                    unset($input['logs']);
                    $input['collect_problems'] = false;
                } else {
                    $input['signals'] = app(ProblemCollector::class)->input();
                }
                $horizon = $gateway === null ? null : app(GatewayHorizon::class)->find(Node::query()->findOrFail($gateway))?->id;
                $nodes = [];
                foreach (Arr::array($input, 'nodes') as $value) {
                    $node = ValidatedData::object($value);
                    $resources = $node['resources'] instanceof \stdClass ? get_object_vars($node['resources']) : ValidatedData::object($node['resources']);
                    $resources['processes'] = array_values(array_filter(Arr::array($resources, 'processes', []),
                        static fn (array $process): bool => ($process['id'] === $horizon) === ($channel === 'horizon')));
                    $node['resources'] = (object) $resources;
                    $nodes[] = $node;
                }
                $input['nodes'] = $nodes;
                $intent = ValidatedData::object(app(DoctorExecutionInput::class)->forIntent($input)['intent']);
                $prior = ValidatedData::object(ValidatedData::object($stored['contexts'] ?? [])[$channel] ?? []);
                if (! $force && json_encode($prior['intent'] ?? null) === json_encode($intent)
                    && is_string($prior['captured_at'] ?? null) && Carbon::parse($prior['captured_at'])->greaterThan(now()->subMinutes(30))) {
                    continue;
                }
                $contexts[$channel] = ['channel' => $channel, 'revision' => $revision, 'captured_at' => now()->toISOString(),
                    'node_id' => $nodeId, 'intent' => $intent,
                    'expectations' => hash('sha256', json_encode([$nodeId, $intent['nodes'], $intent['families']], JSON_THROW_ON_ERROR))];
            }
            $state->observation = [...$stored, 'revision' => $revision, 'dirty' => [...ValidatedData::object($stored['dirty'] ?? []), ...array_fill_keys($channels, false)],
                'contexts' => [...ValidatedData::object($stored['contexts'] ?? []), ...$contexts]];
            $state->saveQuietly();

            return $contexts;
        });
        foreach ($contexts as $context) {
            app(StartSwampRun::class)->publishObservation($context);
        }
    }

    public function applyScheduled(string $workflowId, array $run, array $result): void
    {
        $channel = match ($workflowId) {
            'fb550568-ab07-4eb4-b5f4-db92e5780dc0' => 'fleet',
            '09fb7a5a-6b19-4fb6-8f14-16382563f822' => 'horizon',
            default => throw new \LogicException('Unknown observation schedule.'),
        };
        DB::transaction(function () use ($channel, $run, $result): void {
            $state = $this->state();
            $stored = $state->observation ?? [];
            $previous = ValidatedData::object(ValidatedData::object($stored['terminals'] ?? [])[$channel] ?? []);
            if (($previous['run_id'] ?? null) === $run['id'] || (is_string($previous['started_at'] ?? null)
                && Carbon::parse($previous['started_at'])->greaterThanOrEqualTo(Carbon::parse(Arr::string($run, 'startedAt'))))) {
                return;
            }
            $context = ValidatedData::object(ValidatedData::object($stored['contexts'] ?? [])[$channel] ?? []);
            $usable = ($run['status'] ?? null) === 'succeeded' && (ValidatedData::object($stored['dirty'] ?? [])[$channel] ?? true) === false
                && $context !== [] && ($result['channel'] ?? null) === $channel
                && is_int($result['revision'] ?? null) && $result['revision'] > 0 && $result['revision'] <= $context['revision']
                && ($result['expectations'] ?? null) === ($context['expectations'] ?? null) && is_array($result['observation'] ?? null);
            if ($usable) {
                $native = ValidatedData::object($result['observation']);
                $report = app(DoctorProjection::class)->report(ValidatedData::object($native['report'] ?? null));
                $collector = app(ProblemCollector::class);
                $collector->recordDoctor($report, Carbon::parse(Arr::string($run, 'startedAt')));
                if ($channel === 'fleet') {
                    $collector->recordLogs(ValidatedData::object($native['logs'] ?? null), isset($result['expected_log_cursor']) ? ValidatedData::object($result['expected_log_cursor']) : null);
                    $collector->recordSignals(ValidatedData::object($native['signals'] ?? null));
                }
            }
            // Cursor updates can save the same collector row. Reload before saving our separate replay marker.
            $state->refresh();
            $stored = $state->observation ?? [];
            $stored['terminals'] = [...ValidatedData::object($stored['terminals'] ?? []), $channel => ['run_id' => $run['id'], 'started_at' => $run['startedAt'],
                'status' => $usable ? 'recorded' : 'unavailable']];
            $state->observation = $stored;
            $state->saveQuietly();
            DB::afterCommit(fn () => $this->notify($channel, ! $usable));
        });
    }

    private function notify(?string $channel, bool $force = false): void
    {
        try {
            if ($channel !== 'fleet') {
                $this->publish('horizon', $force);
            }
            if ($channel !== 'horizon') {
                if (GatewayQueueRedis::configured()) {
                    PublishObservationContext::dispatch('fleet', $force);
                } else {
                    $this->publish('fleet', $force);
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Public observation context is unavailable.', ['exception_type' => $exception::class]);
        }
    }

    private function state(): ProblemCollectorState
    {
        return ProblemCollectorState::withoutEvents(fn (): ProblemCollectorState => ProblemCollectorState::query()->lockForUpdate()->orderBy('id')->firstOrCreate());
    }
}
