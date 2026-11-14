<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceMachineName;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\Power\ContainerResume;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Orbit\Extensions\Conn\Actions\AdmitConnEnvironmentRestartAction;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Throwable;

/**
 * Restarts an idle container T3 server whose label is not yet the machine name ([Restart for the machine name](/reference/conn#restart-for-the-machine-name)).
 * Stock T3 reads the name only when it starts. The Gateway reads every label at once, then each candidate's threads with its admin
 * session right before that Instance's own admission. The busy read stays here so the busy rule has one place: a re-read in the run
 * would need the admin session as a vault execution copy and a second copy of ConnAgentActivity's rule outside PHP.
 *
 * A request stays well inside the request-path bound of ADR 0218: past the deadline, the remaining Instances answer `skipped` with
 * `deadline`, and past the restarts of one call they answer `skipped` with `next_run`, so a Node does not crowd the Swamp workers.
 */
final readonly class ConnEnvironmentRestart
{
    /** One label read waits at most this long; all labels of a call are read at once. */
    public const float LabelSeconds = 5.0;

    public function __construct(
        private T3ServerClient $client,
        private AdmitConnEnvironmentRestartAction $admit,
        private int $deadlineSeconds = 120,
        private int $restartsPerCall = 4,
    ) {}

    public function instance(Instance $instance, ?string $triggeredBy, ?string $requestId): ConnEnvironmentRestartDecision
    {
        $instance->loadMissing('project');

        return $this->walk([[$instance, InstanceRuntime::query()->where('instance_id', $instance->id)->first()]], $triggeredBy, $requestId)[0];
    }

    /** @return list<ConnEnvironmentRestartDecision> The Node's container Instances by ID. */
    public function node(Node $node, ?string $triggeredBy, ?string $requestId): array
    {
        $pairs = InstanceRuntime::query()->where('node_id', $node->id)->whereHas('instance')->with('instance.project')->orderBy('instance_id')->get()
            ->map(static fn (InstanceRuntime $runtime): array => [$runtime->instance, $runtime])->all();

        return $this->walk(array_values($pairs), $triggeredBy, $requestId);
    }

    /**
     * @param  list<array{0: Instance, 1: InstanceRuntime|null}>  $pairs
     * @return list<ConnEnvironmentRestartDecision>
     */
    private function walk(array $pairs, ?string $triggeredBy, ?string $requestId): array
    {
        $deadline = CarbonImmutable::now()->addSeconds($this->deadlineSeconds);
        $decisions = [];
        $candidates = [];
        foreach ($pairs as $index => [$instance, $runtime]) {
            $environment = ConnEnvironment::query()->where('instance_id', $instance->id)->first();
            if ($runtime instanceof InstanceRuntime && $runtime->power_state !== InstanceRuntimePowerState::Running) {
                $decisions[$index] = ConnEnvironmentRestartDecision::skipped($instance, ContainerResume::frozenBySleep($instance) ? 'frozen' : 'stopped');
            } elseif (! $runtime instanceof InstanceRuntime || ! InstanceContainer::runsT3($runtime) || ! $environment instanceof ConnEnvironment) {
                $decisions[$index] = ConnEnvironmentRestartDecision::skipped($instance, 'no_t3');
            } else {
                $candidates[$index] = [$instance, $runtime, $environment];
            }
        }
        $labels = $this->client->describes(array_map(static fn (array $candidate): string => $candidate[2]->url, $candidates), self::LabelSeconds);
        $restarts = 0;
        foreach ($candidates as $index => [$instance, $runtime, $environment]) {
            $name = InstanceMachineName::presented($instance->name, $instance->project->slug);
            $label = $labels[$index] ?? null;
            $decisions[$index] = match (true) {
                ! $label instanceof T3EnvironmentDescriptor => ConnEnvironmentRestartDecision::skipped($instance, 't3_unreadable'),
                $label->label === $name => ConnEnvironmentRestartDecision::current($instance),
                CarbonImmutable::now()->greaterThanOrEqualTo($deadline) => ConnEnvironmentRestartDecision::skipped($instance, 'deadline'),
                $restarts >= $this->restartsPerCall => ConnEnvironmentRestartDecision::skipped($instance, 'next_run'),
                default => $this->restart($instance, $runtime, $environment, $name, $triggeredBy, $requestId),
            };
            if ($decisions[$index]->answer === 'restarting') {
                $restarts++;
            }
        }
        ksort($decisions);

        return array_values($decisions);
    }

    /** One candidate: its threads, read right before its admission. Any failure answers this Instance alone; the walk goes on. */
    private function restart(Instance $instance, InstanceRuntime $runtime, ConnEnvironment $environment, string $name, ?string $triggeredBy, ?string $requestId): ConnEnvironmentRestartDecision
    {
        try {
            if ($this->connOperationOpen($environment)) {
                return ConnEnvironmentRestartDecision::busy($instance, 'conn_operation');
            }
            $blocked = ConnAgentActivity::restartBlocked($this->client->shell($environment->url, $environment->admin_session));
            if ($blocked === null) {
                return ConnEnvironmentRestartDecision::skipped($instance, 't3_unreadable');
            }
            if ($blocked) {
                return ConnEnvironmentRestartDecision::busy($instance, 't3_working');
            }

            return ConnEnvironmentRestartDecision::restarting($instance, $this->admit->execute($instance, $runtime, $environment, $name, now()->toImmutable(), $triggeredBy, $requestId));
        } catch (T3ServerException) {
            return ConnEnvironmentRestartDecision::skipped($instance, 't3_unreadable');
        } catch (ResourceOperationException $refused) {
            // A failed dispatch names the Operation it recorded as failed; any other refusal started nothing.
            $operation = is_int($refused->details['operation_id'] ?? null) ? Operation::query()->find($refused->details['operation_id']) : null;

            return $operation instanceof Operation ? ConnEnvironmentRestartDecision::failed($instance, $refused->errorCode, $operation)
                : ConnEnvironmentRestartDecision::busy($instance, $refused->errorCode);
        } catch (Throwable $error) {
            Log::warning('conn.environment_restart_failed', ['instance_id' => $instance->id, 'exception' => $error::class]);

            return ConnEnvironmentRestartDecision::failed($instance, 'conn.restart_admission_failed', null);
        }
    }

    /**
     * Another Conn Operation on the server, such as a renewal, a pairing, a held session or a session cleanup, holds the server's own
     * lock. The restart holds the Instance's lock, so it waits for those instead of cutting their T3 calls.
     */
    private function connOperationOpen(ConnEnvironment $environment): bool
    {
        return Operation::query()->whereNotIn('status', OperationStatus::terminalValues())
            ->where(static fn (Builder $query) => $query->where('lock_key', OperationStore::lockKey('conn_environment', $environment->id))
                ->orWhere(static fn (Builder $query) => $query->where('target_type', 'conn_environment')->where('target_id', $environment->id)))
            ->exists();
    }
}
