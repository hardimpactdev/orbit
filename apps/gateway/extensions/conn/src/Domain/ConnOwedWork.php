<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Shared\ResourceOperationException;
use App\Models\InstanceRuntime;
use App\Models\Node;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Orbit\Extensions\Conn\Actions\AdmitConnCleanupAction;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

/**
 * The work a container T3 server is owed, carried in its rows across a Gateway restart ([Owed work](/reference/conn#owed-work)): the
 * revokes of its withdrawn held sessions and the cleanup of its earlier Gateway sessions. Each end of a Conn Operation on the server,
 * and its wake, admits the next of it, so no renewal tick is needed. A sleeping or removed server starts nothing; its wake does.
 */
final readonly class ConnOwedWork
{
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
}
