<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\StartInstanceDeployAction;
use App\Domain\Instances\Deployment\ContainerDeployOutcome;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofSeeds;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The release hook of the nested fleet (docs/cli/instance/container/fleet-refresh.mmd): after a Gateway release, it admits one
 * `instance.deploy` to the release commit for each nested-fleet default whose runtime keeps a fleet. Best effort: a refusal is a line
 * of the result, never an exception, so the release stays live. A default with a deployment open refuses by its Instance lock, so a
 * repeat starts nothing twice.
 */
final readonly class NestedFleetRefresh
{
    public const string Trigger = 'release';

    private const int Attempts = 3;

    private const int RetryMinutes = 10;

    private const array DeferredRefusals = ['operation.target_busy', 'instance.clone_in_flight', 'deployment.source_changed'];

    /** The line of a default whose admission failed with an error that is no refusal. */
    public const string AdmissionFailed = 'operation.admission_failed';

    public function __construct(private StartInstanceDeployAction $deploy) {}

    /** Whether the operator turned the release refresh on (`ORBIT_INSTANCES_NESTED_FLEET_REFRESH`); off by default. */
    public static function enabled(): bool
    {
        return config('instances.nested_fleet_refresh') === true;
    }

    /**
     * @return array<string, string>|null `<project>/<instance> on <node>` => `operation:<id>` or the refusal code; empty when no default
     *                                    keeps a fleet; null when the refresh is off, which admits and reads nothing
     */
    public function admitAll(string $commit): ?array
    {
        if (! self::enabled()) {
            return null;
        }
        $results = [];
        foreach ($this->defaults() as $instance) {
            $label = $instance->project->slug.'/'.$instance->name.' on '.$instance->node->name;
            try {
                $results[$label] = 'operation:'.$this->deploy->pinned($instance, self::Trigger, null, $commit, release: true)->id;
            } catch (ResourceOperationException $refused) {
                $results[$label] = $refused->errorCode;
            } catch (Throwable $failed) {
                // One default's failed admission never holds the others, and the release step still ends.
                report($failed);
                $results[$label] = self::AdmissionFailed;
            }
        }
        Log::info('Nested-fleet refresh admissions.', ['commit' => $commit, 'defaults' => $results]);

        return $results;
    }

    /**
     * Continue the verified release from durable Operation and seed records. The release lock serializes callers
     * across fleet jobs. An Operation admitted just before a restart is found again by its pinned commit.
     *
     * @param  array<string, mixed>  $previous
     * @param  (\Closure(int, array<string, mixed>): void)|null  $recordDefault
     * @return array{status: string, defaults: array<int, array<string, mixed>>}
     */
    public function advance(string $commit, array $previous = [], ?\Closure $recordDefault = null): array
    {
        if (! self::enabled()) {
            return ['status' => 'disabled', 'defaults' => []];
        }
        $defaults = [];
        // Keep the completed seed identity even if the source disappears before publication.
        foreach ((array) ($previous['defaults'] ?? []) as $id => $row) {
            if (is_int($id) && is_array($row) && ($row['status'] ?? null) === 'current') {
                $defaults[$id] = ValidatedData::object($row);
            }
        }
        foreach ($this->defaults() as $instance) {
            $row = ValidatedData::object(data_get($previous, 'defaults.'.$instance->id, []));
            if (! isset($defaults[$instance->id])) {
                $defaults[$instance->id] = $this->advanceDefault($instance, $commit, $row);
                $recordDefault?->__invoke($instance->id, $defaults[$instance->id]);
            }
        }
        $states = array_column($defaults, 'status');

        return [
            'status' => in_array('pending', $states, true) ? 'pending' : (in_array('failed', $states, true) ? 'failed' : 'current'),
            'defaults' => $defaults,
        ];
    }

    /**
     * @param  array<string, mixed>  $previous
     * @return array<string, mixed>
     */
    private function advanceDefault(Instance $instance, string $commit, array $previous): array
    {
        // The release's own attempts, by the admission's record (`origin`, or the Operation this row stored before the input named
        // it), never by `triggered_by`, which any caller names.
        $linked = is_int($previous['operation_id'] ?? null) ? $previous['operation_id'] : null;
        $attempts = Operation::query()->where('kind', OperationKinds::InstanceDeploy)
            ->where('target_type', OperationKinds::InstanceTarget)->where('target_id', $instance->id)->where('input->commit', $commit)
            ->where(static fn ($query) => $query->where('input->origin', ContainerDeployOutcome::ReleaseOrigin)
                ->when($linked !== null, static fn ($query) => $query->orWhere('id', $linked)))
            ->latest('id')->limit(self::Attempts)->get();
        $last = $attempts->first();
        $failures = ValidatedData::integer($previous['admission_failures'] ?? 0);
        $receipt = $last === null ? [] : ['operation_id' => $last->id];
        if ($failures > 0) {
            $receipt['admission_failures'] = $failures;
        }
        if ($last !== null && (! $last->status->terminal() || $last->lock_key !== null)) {
            return ['status' => 'pending', ...$receipt];
        }
        $runtime = $instance->runtime;
        if ($last !== null && $last->status === OperationStatus::Succeeded && ($last->result['outcome'] ?? null) === ContainerDeployOutcome::AlreadyNewer) {
            // The default's checkout was already past the release commit: the run kept it, and its seed, as they were. Only the seed of
            // the checkout that the run read publishes; any other seed, the release commit's included, has moved.
            $kept = $last->result['kept_commit'] ?? null;
            if (is_string($kept) && $runtime?->seed_commit === $kept && $runtime->seed_taken_at !== null) {
                return ['status' => 'current', 'already_newer' => true, 'commit' => $kept, 'taken_at' => $runtime->seed_taken_at->toIso8601String(),
                    'copies_to_ci' => app(ProofSeeds::class)->sends($instance, $runtime), ...$receipt];
            }

            return ['status' => 'failed', 'error_code' => 'instance.release_seed_moved', ...$receipt];
        }
        if ($runtime?->seed_commit === $commit && $runtime->seed_taken_at !== null) {
            return ['status' => 'current', 'commit' => $commit, 'taken_at' => $runtime->seed_taken_at->toIso8601String(),
                'copies_to_ci' => app(ProofSeeds::class)->sends($instance, $runtime), ...$receipt];
        }
        if ($last !== null) {
            if ($last->status === OperationStatus::Succeeded) {
                return ['status' => 'failed', 'error_code' => 'instance.release_seed_moved', ...$receipt];
            }
            if ($attempts->count() + $failures >= self::Attempts) {
                return ['status' => 'failed', 'error_code' => $last->error_code, ...$receipt];
            }
            if ($last->finished_at?->gt(now()->subMinutes(self::RetryMinutes))) {
                return ['status' => 'pending', 'error_code' => $last->error_code, ...$receipt];
            }
        }
        if (($previous['error_code'] ?? null) === 'project.source_unavailable'
            && (($previous['status'] ?? null) === 'failed' || now()->timestamp < ValidatedData::integer($previous['retry_at']))) {
            return $previous;
        }
        try {
            $operation = $this->deploy->pinned($instance, self::Trigger, null, $commit, release: true);
            // An admission that returns an ended, unlocked Operation (a replay) is read on this pass, never waited for.
            if ($operation->status->terminal() && $operation->lock_key === null && $operation->id !== $last?->id) {
                return $this->advanceDefault($instance, $commit, [...$previous, 'operation_id' => $operation->id]);
            }

            return [...$receipt, 'status' => 'pending', 'operation_id' => $operation->id];
        } catch (ResourceOperationException $refused) {
            if ($refused->errorCode === 'project.source_unavailable') {
                $failures++;

                return [...$receipt, 'status' => $attempts->count() + $failures >= self::Attempts ? 'failed' : 'pending',
                    'error_code' => $refused->errorCode, 'admission_failures' => $failures,
                    'retry_at' => now()->addMinutes(self::RetryMinutes)->timestamp];
            }
            $status = in_array($refused->errorCode, self::DeferredRefusals, true) ? 'pending' : 'failed';

            return ['status' => $status, 'error_code' => $refused->errorCode, ...$receipt];
        }
    }

    /** @return list<Instance> */
    private function defaults(): array
    {
        $runtimes = InstanceRuntime::query()->where('incus_project', InstancePlacement::NestedProject)
            ->whereNull('clone_source_instance_id')->whereNull('t3_port')->orderBy('instance_id')->get();
        $defaults = [];
        foreach ($runtimes as $runtime) {
            $instance = Instance::query()->with(['project', 'node'])->find($runtime->instance_id);
            if ($instance !== null && $instance->name === DefaultInstanceName::Name
                && InstancePlacement::keepsFleet($instance->project, $instance->node, $runtime->incus_project)) {
                $defaults[] = $instance;
            }
        }

        return $defaults;
    }
}
