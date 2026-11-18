<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\StartInstanceDeployAction;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
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

    private const array DeferredRefusals = ['operation.target_busy', 'instance.clone_in_flight'];

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
                $results[$label] = 'operation:'.$this->deploy->pinned($instance, self::Trigger, null, $commit)->id;
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
     * @return array{status: string, defaults: array<int, array<string, mixed>>}
     */
    public function advance(string $commit): array
    {
        if (! self::enabled()) {
            return ['status' => 'disabled', 'defaults' => []];
        }
        $defaults = [];
        foreach ($this->defaults() as $instance) {
            $defaults[$instance->id] = $this->advanceDefault($instance, $commit);
        }
        $states = array_column($defaults, 'status');

        return [
            'status' => in_array('pending', $states, true) ? 'pending' : (in_array('failed', $states, true) ? 'failed' : 'current'),
            'defaults' => $defaults,
        ];
    }

    /** @return array<string, mixed> */
    private function advanceDefault(Instance $instance, string $commit): array
    {
        $attempts = Operation::query()->where('kind', OperationKinds::InstanceDeploy)
            ->where('target_type', OperationKinds::InstanceTarget)->where('target_id', $instance->id)
            ->where('triggered_by', self::Trigger)->where('input->commit', $commit)->latest('id')->limit(self::Attempts)->get();
        $last = $attempts->first();
        $receipt = $last === null ? [] : ['operation_id' => $last->id];
        if ($last !== null && (! $last->status->terminal() || $last->lock_key !== null)) {
            return ['status' => 'pending', ...$receipt];
        }
        $runtime = $instance->runtime;
        if ($runtime?->seed_commit === $commit && $runtime->seed_taken_at !== null) {
            return ['status' => 'current', 'commit' => $commit, 'taken_at' => $runtime->seed_taken_at->toIso8601String(), ...$receipt];
        }
        if ($last !== null) {
            if ($last->status === OperationStatus::Succeeded) {
                return ['status' => 'failed', 'error_code' => 'instance.release_seed_moved', ...$receipt];
            }
            if ($attempts->count() >= self::Attempts) {
                return ['status' => 'failed', 'error_code' => $last->error_code, ...$receipt];
            }
            if ($last->finished_at?->gt(now()->subMinutes(self::RetryMinutes))) {
                return ['status' => 'pending', 'error_code' => $last->error_code, ...$receipt];
            }
        }
        try {
            $operation = $this->deploy->pinned($instance, self::Trigger, null, $commit);

            return ['status' => 'pending', 'operation_id' => $operation->id];
        } catch (ResourceOperationException $refused) {
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
