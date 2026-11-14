<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Models\ConnPairing;

/**
 * The hourly care of held sessions ([Held sessions](/reference/conn#held-sessions)). No device takes part. It withdraws a session whose
 * Node lost its grant, renews each session within the margin of its end, also on Instances no device uses, and admits each owed revoke
 * while its server runs. A sleeping Instance is skipped; its wake renews its sessions.
 */
final readonly class ConnHeldSessionSweep
{
    /** Clones made on one day reach the margin in the same hour; one tick renews at most this many. */
    public const int PerTick = 20;

    public function __construct(private ConnHeldSessions $held, private ConnServerAccess $access) {}

    /** @return list<int> the Operation ids it admitted */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $admitted = [];
        foreach (ConnPairing::query()->where('held', true)->whereNull('withdrawn_at')->whereNull('revoked_at')->with(['node', 'environment.node'])->get() as $pairing) {
            if (! $this->access->allows($pairing->node, $pairing->environment)) {
                DB::transaction(fn () => $this->held->withdraw($pairing));
            }
        }
        $open = Operation::query()->where('kind', ConnHeldSessions::Kind)->whereNotIn('status', OperationStatus::terminalValues())->get()
            ->map(static fn (Operation $operation): string => ValidatedData::integer($operation->input['caller_node_id']).':'.ValidatedData::integer($operation->input['conn_environment_id']))->flip();
        $due = $this->running(ConnHeldSessions::due($now))->orderBy('session_expires_at')->orderBy('id')->get();
        // A session whose last renewal failed goes after the others, so failing ones never take every slot from healthy ones. A due
        // session's every renewal failed: a success withdraws it.
        $failed = Operation::query()->where('kind', ConnHeldSessions::Kind)->whereIn('status', [OperationStatus::Failed->value, OperationStatus::Interrupted->value, OperationStatus::Cancelled->value])
            ->whereIn('input->renews_pairing_id', $due->modelKeys())->get()
            ->map(static fn (Operation $operation): int => ValidatedData::integer($operation->input['renews_pairing_id']))->flip();
        $due = $due->sortBy(static fn (ConnPairing $pairing): int => $failed->has($pairing->id) ? 1 : 0)->values();
        foreach ($due as $pairing) {
            if (count($admitted) >= self::PerTick) {
                break;
            }
            if (! $open->has($pairing->node_id.':'.$pairing->conn_environment_id) && ($operation = $this->held->renew($pairing)) instanceof Operation) {
                $admitted[] = $operation->id;
            }
        }
        // A session past its end needs no revoke; the server refuses it already. Each revoke's end starts the next one on its server.
        $this->held->settleEnded($now);
        $owed = $this->running(ConnPairing::query()->where('held', true)->whereNotNull('withdrawn_at'))->pluck('conn_environment_id')->unique();
        foreach ($owed as $environment) {
            if (($operation = $this->held->revokeNext(ValidatedData::integer($environment))) instanceof Operation) {
                $admitted[] = $operation->id;
            }
        }

        return $admitted;
    }

    /**
     * @param  Builder<ConnPairing>  $query
     * @return Builder<ConnPairing>
     */
    private function running(Builder $query): Builder
    {
        return $query->whereHas('environment', static fn (Builder $environment) => $environment->whereNotNull('instance_id')
            ->whereHas('instance', static fn (Builder $instance) => $instance->where('status', '!=', InstanceState::Removing->value))
            ->whereDoesntHave('instance.runtime', static fn (Builder $runtime) => $runtime->where('power_state', InstanceRuntimePowerState::Stopped->value)));
    }
}
