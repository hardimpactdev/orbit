<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Orbit\Extensions\Conn\Actions\AdmitConnCleanupAction;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

/**
 * The work a container T3 server is owed, carried in its rows across a Gateway restart ([Owed work](/reference/conn#owed-work)): the
 * revokes of its withdrawn held sessions and the cleanup of its earlier Gateway sessions. Each end of a Conn Operation on the server,
 * and its wake, admits the next of it, so no renewal tick is needed. A sleeping or removed server starts nothing; its wake does. The
 * reconcile tick admits what no such event will: a failed revoke or cleanup, and work whose after-commit admission a crash lost.
 */
final readonly class ConnOwedWork
{
    /** A server whose last revoke or cleanup failed is tried again no sooner than this. */
    public const int RetryMinutes = 15;

    /** The Operations that carry a server's owed work. */
    private const array Kinds = ['conn.pairing_revoke', 'conn.gateway_sessions_revoke'];

    public function __construct(private ConnHeldSessions $held) {}

    public function advance(int $environment): void
    {
        $environment = ConnEnvironment::query()->with('instance')->find($environment);
        if (! $environment instanceof ConnEnvironment || $environment->instance_id === null || $environment->instance?->status === InstanceState::Removing
            || InstanceRuntime::query()->where('instance_id', $environment->instance_id)->where('power_state', InstanceRuntimePowerState::Stopped->value)->exists()) {
            return;
        }
        $this->held->settleEnded(CarbonImmutable::now());
        $this->held->revokeNext($environment->id);
        if ($environment->cleanup_version > $environment->cleanup_completed_version && $environment->node_id !== null) {
            try {
                app(AdmitConnCleanupAction::class)->execute($environment, Node::query()->findOrFail($environment->node_id));
            } catch (ResourceOperationException $error) {
                Log::info('conn.cleanup_deferred', ['environment_id' => $environment->environment_id, 'error_code' => $error->errorCode]);
            }
        }
    }

    /**
     * Advances each container server that owes work, unless its last revoke or cleanup failed within the retry window. A server that
     * runs one, sleeps or is being removed starts nothing.
     *
     * @return list<int> the servers it advanced
     */
    public function reconcile(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $owing = ConnEnvironment::query()->whereNotNull('instance_id')
            ->where(static fn ($query) => $query->whereColumn('cleanup_version', '>', 'cleanup_completed_version')
                ->orWhereIn('id', ConnPairing::query()->where('held', true)->whereNotNull('withdrawn_at')->select('conn_environment_id')))
            ->orderBy('id')->pluck('id');
        $advanced = [];
        foreach ($owing as $id) {
            $environment = ValidatedData::integer($id);
            $last = Operation::query()->whereIn('kind', self::Kinds)->where('input->conn_environment_id', $environment)->latest('id')->first();
            if ($last instanceof Operation && $last->status === OperationStatus::Failed
                && ($last->finished_at ?? $last->updated_at)?->gt($now->subMinutes(self::RetryMinutes))) {
                continue;
            }
            $this->advance($environment);
            $advanced[] = $environment;
        }

        return $advanced;
    }
}
