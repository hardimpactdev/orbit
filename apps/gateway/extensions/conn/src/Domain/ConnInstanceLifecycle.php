<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\InstanceLifecycleContribution;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Orbit\Extensions\Conn\Actions\AdmitConnCleanupAction;
use Orbit\Extensions\Conn\Actions\AdmitConnRenewalAction;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

/**
 * Records a clone's T3 server from its create flow's result ([Instance servers](/reference/conn#instance-servers)). The run checked the
 * served environment id and the session's scopes in the container, so the Gateway does not call T3 again. The record names the
 * Instance, so no Node can register the server itself. Loaded while Conn is off too, so an admitted create can still finish.
 */
final readonly class ConnInstanceLifecycle implements InstanceLifecycleContribution
{
    public function __construct(private ExtensionStore $extensions) {}

    public function assertCreateAvailable(): void
    {
        // The container run creates T3 and its admin session. Recording that result works while Conn is off;
        // enabling Conn later makes the recorded server available to pairing and session renewal.
    }

    public function projectDestroy(Instance $instance): void
    {
        $environments = ConnEnvironment::query()->where('instance_id', $instance->id)->lockForUpdate()->get();
        app(ConnEnvironmentRemoval::class)->remove($environments);
    }

    /**
     * A woken Instance's server renews at once when its session ends within the sweep's margin or has ended: the sweep skips a
     * sleeping Instance, and the run issues a new session without the old one. Its held sessions within the margin renew too. A busy
     * server or Conn switched off starts nothing.
     */
    public function projectWake(Instance $instance, bool $retryQueueFailure = false): void
    {
        $environment = ConnEnvironment::query()->where('instance_id', $instance->id)->first();
        if (! $environment instanceof ConnEnvironment || ! $this->extensions->enabled('conn')) {
            return;
        }
        if ($environment->admin_session_expires_at->lt(Carbon::now()->addDays(ConnRenewalSweep::MarginDays))) {
            try {
                app(AdmitConnRenewalAction::class)->execute($environment);
            } catch (ResourceOperationException $error) {
                Log::warning('conn.renewal_start_failed', ['environment_id' => $environment->environment_id, 'error_code' => $error->errorCode]);
            }
        }
        // Held sessions renew with the session they find; one that waits on an ended Gateway session renews once that is recorded.
        // The revokes and cleanup owed while it slept start too; what the server cannot take yet starts at the end of what holds it
        // ([Owed work](/reference/conn#owed-work)).
        if ($environment->admin_session_expires_at->isFuture()) {
            app(ConnHeldSessions::class)->renewDue($environment, retryQueueFailure: $retryQueueFailure);
            app(ConnOwedWork::class)->advance($environment->id);
            // A server that takes until-revoked keeps its sessions from here on ([Session lifetimes](/reference/conn#session-lifetimes)).
            app(ConnSessionLifetime::class)->migrate($environment);
        }
    }

    /** The clone's Conn environment, written by `projectCreate`; it stays while Conn is off, so an admitted completion can still run. */
    public function registered(Instance $instance): bool
    {
        return ConnEnvironment::query()->where('instance_id', $instance->id)->exists();
    }

    /** The clone's T3 server keeps its id, sessions and pairings; only its label follows the new name. */
    public function projectRename(Instance $instance, string $label): void
    {
        ConnEnvironment::query()->where('instance_id', $instance->id)->update(['label' => $label]);
    }

    /** Records the container server's reported version after a server switch or wake, keeping its address and pairings. */
    public function projectServerVersion(Instance $instance, string $serverVersion): void
    {
        ConnEnvironment::query()->where('instance_id', $instance->id)->update(['server_version' => $serverVersion]);
    }

    /** A reserved clone's create issues the reserving device's pairing link with its sessions ([Reserve a clone](/reference/conn#reserve-a-clone)). */
    public function createPairing(Operation $operation): ?array
    {
        return $this->extensions->enabled('conn') ? app(ConnReservations::class)->createPairing($operation) : null;
    }

    public function projectCreate(Instance $instance, array $server): void
    {
        $id = ValidatedData::string($server['environment_id']);
        $environment = ConnEnvironment::query()->where('environment_id', $id)->lockForUpdate()->first();
        if ($environment instanceof ConnEnvironment && $environment->instance_id !== null && $environment->instance_id !== $instance->id) {
            throw new ResourceOperationException('instance.result_invalid', "Environment {$id} is the T3 server of another Instance.", 409);
        }
        $replaced = $environment instanceof ConnEnvironment;
        $environment ??= new ConnEnvironment(['environment_id' => $id]);
        // The bumped revision also stops a Node's registration of this id that read the row before this write
        // (docs/reference/conn.md#registration): it compares the revision again in its lock.
        $environment->fill(['label' => ValidatedData::string($server['label']), 'url' => ValidatedData::string($server['url']),
            'node_id' => $instance->node_id, 'server_version' => ValidatedData::string($server['server_version']),
            'admin_session' => ValidatedData::string($server['admin_session']),
            'admin_session_expires_at' => Carbon::parse(ValidatedData::string($server['expires_at'])), 'registered_at' => Carbon::now(),
            'cleanup_version' => $environment->cleanup_version + 1]);
        // A new server holds no earlier Gateway session, so it has nothing to clean.
        if (! $replaced) {
            $environment->cleanup_completed_version = $environment->cleanup_version;
        }
        $environment->forceFill(['instance_id' => $instance->id])->save();
        // The link the create issued is the reservation's; without it, the reservation mints one after the commit.
        if ($this->extensions->enabled('conn') && is_array($server['pairing'] ?? null)) {
            app(ConnReservations::class)->recordPairing($instance, $environment, ValidatedData::object($server['pairing']));
        }
        // The creating profile's Nodes get their held sessions now, so their first attach is one read.
        // A reserved clone whose create issued no pairing link mints one in the background.
        if ($this->extensions->enabled('conn')) {
            DB::afterCommit(static function () use ($instance, $environment): void {
                app(ConnHeldSessions::class)->preMint($instance, $environment);
                app(ConnReservations::class)->schedulePairing($instance, $environment);
            });
        }
        if ($replaced) {
            DB::afterCommit(function () use ($environment, $instance): void {
                try {
                    app(AdmitConnCleanupAction::class)->execute($environment, Node::query()->findOrFail($instance->node_id));
                } catch (ResourceOperationException $error) {
                    Log::warning('conn.cleanup_start_failed', ['environment_id' => $environment->environment_id, 'error_code' => $error->errorCode]);
                }
            });
        }
    }
}
