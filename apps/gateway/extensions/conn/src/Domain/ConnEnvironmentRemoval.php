<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Infrastructure\Swamp\SwampScheduleWorkflows;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnManagedRevision;
use Orbit\Extensions\Conn\Models\ConnManagedTarget;
use Orbit\Extensions\Conn\Models\ConnManagedToken;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Orbit\Extensions\Conn\Models\ConnReservation;
use Orbit\Extensions\Conn\Models\ConnThread;
use Throwable;

/**
 * Removes Conn environment records and everything the Gateway holds for them inside the caller's write transaction,
 * without contacting any T3 server. Shared by Instance destroy (`ConnInstanceLifecycle::projectDestroy`) and host
 * server unregistration (`UnregisterConnEnvironmentAction`).
 */
final readonly class ConnEnvironmentRemoval
{
    public function __construct(private ConnAttachedThreads $attachedThreads) {}

    /**
     * @param  iterable<ConnEnvironment>  $environments
     */
    public function remove(iterable $environments): void
    {
        $copies = [];
        $environmentIds = [];

        foreach ($environments as $environment) {
            // Only Conn's own kinds name a server, so the kind index bounds the read: a JSON match over every operation scans the
            // whole table inside the write lock.
            foreach (Operation::query()->where('kind', '>=', 'conn.')->where('kind', '<', 'conn/')
                ->where('input->conn_environment_id', $environment->id)->get() as $operation) {
                foreach (ValidatedData::object($operation->input['execution_secrets'] ?? []) as $rawCopy) {
                    $copy = ValidatedData::object($rawCopy);
                    $copies[] = [ValidatedData::string($copy['vault']), ValidatedData::string($copy['key'])];
                }
            }

            $targetIds = ConnManagedTarget::query()->where('conn_environment_id', $environment->id)->pluck('id')->all();
            if ($targetIds !== []) {
                ConnManagedToken::query()->whereIn('conn_managed_target_id', $targetIds)->delete();
                ConnManagedRevision::query()->whereIn('conn_managed_target_id', $targetIds)->delete();
                ConnManagedTarget::query()->whereIn('id', $targetIds)->delete();
            }

            ConnThread::query()->where('conn_environment_id', $environment->id)->delete();
            ConnThread::hostQuery()->where('conn_environment_id', $environment->id)->delete();
            ConnReservation::query()
                ->whereIn('conn_pairing_id', ConnPairing::query()->where('conn_environment_id', $environment->id)->select('id'))
                ->update(['conn_pairing_id' => null]);
            ConnPairing::query()->where('conn_environment_id', $environment->id)->delete();
            $environmentIds[] = $environment->environment_id;
            $environment->delete();
        }

        if ($environmentIds !== []) {
            $this->attachedThreads->drop($environmentIds);
        }

        if ($copies !== []) {
            // Vault deletes are Swamp round trips, so they run after the transaction commits and never hold the write lock. The
            // copies only reach a server that is gone now, so a failed delete is logged and does not undo the committed removal.
            DB::afterCommit(static function () use ($copies): void {
                foreach ($copies as [$vault, $key]) {
                    try {
                        app(SwampScheduleWorkflows::class)->deleteSecret($vault, $key);
                    } catch (Throwable $error) {
                        Log::warning('conn.destroy_secret_delete_failed', ['vault' => $vault, 'exception_type' => $error::class]);
                    }
                }
            });
        }
    }
}
