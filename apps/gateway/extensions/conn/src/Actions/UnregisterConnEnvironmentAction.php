<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Domain\ConnEnvironmentRemoval;
use Orbit\Extensions\Conn\Domain\ConnServerAccess;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

/**
 * Unregisters a host Conn T3 server from the Gateway registry without contacting the T3 server.
 * Idempotent: returns true when a record was removed and false when none existed.
 */
final readonly class UnregisterConnEnvironmentAction
{
    public function __construct(
        private ConnServerAccess $access,
        private ConnEnvironmentRemoval $removal,
    ) {}

    public function execute(Node $caller, string $environmentId): bool
    {
        return DB::transaction(function () use ($caller, $environmentId): bool {
            $environment = ConnEnvironment::query()
                ->where('environment_id', $environmentId)
                ->lockForUpdate()
                ->first();

            if (! $environment instanceof ConnEnvironment) {
                return false;
            }

            $this->access->assert($caller, $environment);

            if ($environment->instance_id !== null) {
                throw new ResourceOperationException(
                    'conn.environment_is_instance',
                    "{$environment->label} is the T3 server of an Instance; destroy the Instance to remove it.",
                    409,
                );
            }

            $busy = Operation::query()
                ->where('kind', '>=', 'conn.')
                ->where('kind', '<', 'conn/')
                ->whereNotIn('status', OperationStatus::terminalValues())
                ->where(static function (Builder $query) use ($environment): void {
                    $query->where('input->conn_environment_id', $environment->id)
                        ->orWhere('lock_key', OperationStore::lockKey('conn_environment', $environment->id))
                        ->orWhere(static fn (Builder $target): Builder => $target->where('target_type', 'conn_environment')->where('target_id', $environment->id));
                })
                ->exists();

            if ($busy) {
                throw new ResourceOperationException(
                    'operation.target_busy',
                    'A Conn operation is already running for this environment.',
                    409,
                );
            }

            $this->removal->remove([$environment]);

            return true;
        });
    }
}
