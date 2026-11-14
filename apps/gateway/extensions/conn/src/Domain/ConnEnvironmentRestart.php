<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceMachineName;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Instances\Power\InstanceStart;
use App\Domain\Instances\Power\InstanceWake;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use Orbit\Extensions\Conn\Actions\AdmitConnEnvironmentRestartAction;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

/**
 * Restarts an idle container T3 server whose label is not yet the machine name ([Restart for the machine name](/reference/conn#restart-for-the-machine-name)).
 * Stock T3 reads the name only when it starts. The Gateway reads the label, then the threads with its admin session, and admits the
 * restart right after the read: a run cannot hold the session, so this is the tightest window. The Node form reads each container
 * right before its own admission.
 */
final readonly class ConnEnvironmentRestart
{
    public function __construct(private T3ServerClient $client, private AdmitConnEnvironmentRestartAction $admit) {}

    public function instance(Instance $instance, ?string $triggeredBy, ?string $requestId): ConnEnvironmentRestartDecision
    {
        $instance->loadMissing('project');

        return $this->decide($instance, InstanceRuntime::query()->where('instance_id', $instance->id)->first(), $triggeredBy, $requestId);
    }

    /** @return list<ConnEnvironmentRestartDecision> The Node's container Instances by ID. */
    public function node(Node $node, ?string $triggeredBy, ?string $requestId): array
    {
        $decisions = [];
        foreach (InstanceRuntime::query()->where('node_id', $node->id)->whereHas('instance')->with('instance.project')->orderBy('instance_id')->get() as $runtime) {
            $decisions[] = $this->decide($runtime->instance, $runtime, $triggeredBy, $requestId);
        }

        return $decisions;
    }

    private function decide(Instance $instance, ?InstanceRuntime $runtime, ?string $triggeredBy, ?string $requestId): ConnEnvironmentRestartDecision
    {
        if ($runtime instanceof InstanceRuntime && $runtime->power_state !== InstanceRuntimePowerState::Running) {
            return ConnEnvironmentRestartDecision::skipped($instance, $this->asleep($instance));
        }
        $environment = ConnEnvironment::query()->where('instance_id', $instance->id)->first();
        if (! $runtime instanceof InstanceRuntime || ! InstanceContainer::runsT3($runtime) || ! $environment instanceof ConnEnvironment) {
            return ConnEnvironmentRestartDecision::skipped($instance, 'no_t3');
        }
        $name = InstanceMachineName::presented($instance->name, $instance->project->slug);
        try {
            if ($this->client->describe($environment->url)->label === $name) {
                return ConnEnvironmentRestartDecision::current($instance);
            }
            $blocked = ConnAgentActivity::restartBlocked($this->client->shell($environment->url, $environment->admin_session));
            $readAt = now()->toImmutable();
        } catch (T3ServerException) {
            return ConnEnvironmentRestartDecision::skipped($instance, 't3_unreadable');
        }
        if ($blocked === null) {
            return ConnEnvironmentRestartDecision::skipped($instance, 't3_unreadable');
        }
        if ($blocked) {
            return ConnEnvironmentRestartDecision::busy($instance, 't3_working');
        }
        try {
            return ConnEnvironmentRestartDecision::restarting($instance, $this->admit->execute($instance, $runtime, $environment, $name, $readAt, $triggeredBy, $requestId));
        } catch (ResourceOperationException $refused) {
            return ConnEnvironmentRestartDecision::busy($instance, $refused->errorCode);
        }
    }

    /** `power_state` keeps a frozen and a stopped container alike: the last succeeded power Operation tells them apart. */
    private function asleep(Instance $instance): string
    {
        $last = Operation::query()->where('target_type', 'instance')->where('target_id', $instance->id)
            ->whereIn('kind', [InstanceSleep::Kind, InstanceWake::Kind, InstanceStart::Kind])->where('status', OperationStatus::Succeeded)
            ->orderByDesc('finished_at')->orderByDesc('id')->first();

        return $last?->kind === InstanceSleep::Kind && ($last->result['sleep'] ?? null) === 'frozen' ? 'frozen' : 'stopped';
    }
}
