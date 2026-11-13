<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\InstanceLifecycleContribution;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampScheduleWorkflows;
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
use Orbit\Extensions\Conn\Models\ConnPairing;

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
        if (! $this->extensions->enabled('conn')) {
            throw new ResourceOperationException('operation.kind_unavailable', 'A clone needs Conn enabled: Conn records its T3 server.', 503);
        }
    }

    public function projectDestroy(Instance $instance): void
    {
        $environments = ConnEnvironment::query()->where('instance_id', $instance->id)->lockForUpdate()->get();
        foreach ($environments as $environment) {
            foreach (Operation::query()->where('input->conn_environment_id', $environment->id)->get() as $operation) {
                foreach (ValidatedData::object($operation->input['execution_secrets'] ?? []) as $rawCopy) {
                    $copy = ValidatedData::object($rawCopy);
                    app(SwampScheduleWorkflows::class)->deleteSecret(ValidatedData::string($copy['vault']), ValidatedData::string($copy['key']));
                }
            }
            ConnPairing::query()->where('conn_environment_id', $environment->id)->delete();
            $environment->delete();
        }
    }

    /**
     * A woken Instance's server renews at once when its session ends within the sweep's margin or has ended: the sweep skips a
     * sleeping Instance, and the run issues a new session without the old one. A busy server or Conn switched off starts nothing.
     */
    public function projectWake(Instance $instance): void
    {
        $environment = ConnEnvironment::query()->where('instance_id', $instance->id)
            ->where('admin_session_expires_at', '<', Carbon::now()->addDays(ConnRenewalSweep::MarginDays))->first();
        if (! $environment instanceof ConnEnvironment || ! $this->extensions->enabled('conn')) {
            return;
        }
        try {
            app(AdmitConnRenewalAction::class)->execute($environment);
        } catch (ResourceOperationException $error) {
            Log::warning('conn.renewal_start_failed', ['environment_id' => $environment->environment_id, 'error_code' => $error->errorCode]);
        }
    }

    /** The clone's Conn environment, written by `projectCreate`; it stays while Conn is off, so an admitted completion can still run. */
    public function registered(Instance $instance): bool
    {
        return ConnEnvironment::query()->where('instance_id', $instance->id)->exists();
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
