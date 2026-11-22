<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Actions\Instances\StartInstanceDestroyAction;
use App\Data\Instances\CreateInstanceData;
use App\Domain\Instances\DevelopmentInstanceBranchAdmission;
use App\Domain\Instances\InstanceBranchSelection;
use App\Domain\Instances\InstanceFinalize;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationRecordCoordinator;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Orbit\Extensions\Conn\Actions\IssueConnPairingAction;
use Orbit\Extensions\Conn\Data\ConnReservationData;
use Orbit\Extensions\Conn\Data\IssuedConnPairingData;
use Orbit\Extensions\Conn\Jobs\MintConnReservationPairing;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Orbit\Extensions\Conn\Models\ConnProfileBinding;
use Orbit\Extensions\Conn\Models\ConnReservation;
use Throwable;

/**
 * Reserved clones (docs/reference/conn.md#reserve-a-clone): a device starts a clone when its user opens a new thread, under a draft name,
 * and gives it its name and branch when the user submits. The heavy parts run while the user types: the copy, its start, T3 with
 * the device's one-time pairing link, and a nested fleet. When the create brings no link, one is minted in the background once the
 * server session is recorded. A reservation nobody finalizes
 * expires ten minutes after its last read and is destroyed. Its own transactions take the reservation's row lock, so a finalize and an
 * expiry never both win.
 */
final readonly class ConnReservations implements OperationRecordCoordinator
{
    /** Minutes an open reservation lives after it was made or last read. */
    public const int LifetimeMinutes = 10;

    /** Open reservations per device; a new one beyond releases the oldest. */
    public const int PerCaller = 3;

    /** The caller of the work a reservation admits, so its records name it. */
    public const string Caller = 'conn-reservation';

    /**
     * The caller's open reservation of the Project (under its current profile), read again and kept alive, or a new one: the create
     * of a clone under a draft name, whose completion waits for its finalize. A Project whose clones get a database, a Valkey user or
     * a Route is refused: their names follow the clone's name from its create on.
     *
     * @return array{ConnReservation, bool}
     */
    public function reserve(Node $caller, Project $project, Instance $default, ?string $requestId): array
    {
        $profileId = self::profileId($caller);
        $open = self::opened()->where('node_id', $caller->id)->where('project_id', $project->id)
            ->when($profileId === null, static fn (Builder $query): Builder => $query->whereNull('conn_profile_id'),
                static fn (Builder $query): Builder => $query->where('conn_profile_id', $profileId))
            ->latest('created_at')->first();
        if ($open instanceof ConnReservation) {
            return [$this->touch($open), false];
        }
        // Room for this one: the device's oldest open reservations beyond the limit go.
        $existing = self::opened()->where('node_id', $caller->id)->oldest('created_at')->get();
        foreach ($existing->take(max(0, $existing->count() - (self::PerCaller - 1))) as $oldest) {
            $this->release($oldest, ConnReservation::Released);
        }
        $data = new CreateInstanceData($project->id, $default->node_id, 'draft-'.bin2hex(random_bytes(4)), null, null, null, completionDeferred: true);
        $operation = app(OperationIdempotency::class)->suspended(fn (): Operation => app(StartDevelopmentInstanceCreateAction::class)->execute($data, 'node:'.$caller->id,
            $requestId, within: static fn (Closure $record): Operation => DB::transaction(static function () use ($record, $caller, $project, $profileId): Operation {
                $operation = $record();
                self::assertSupported($operation);
                ConnReservation::query()->create(['project_id' => $project->id, 'instance_id' => $operation->target_id, 'node_id' => $caller->id,
                    'conn_profile_id' => $profileId, 'create_operation_id' => $operation->id, 'state' => ConnReservation::Open,
                    'expires_at' => now()->addMinutes(self::LifetimeMinutes)]);

                return $operation;
            })));

        return [ConnReservation::query()->where('create_operation_id', $operation->id)->firstOrFail(), true];
    }

    /** Looks up a reservation owned by the calling Node under its current profile binding. */
    public static function owned(Node $caller, string $id): ?ConnReservation
    {
        $reservation = ConnReservation::query()->find($id);
        if (! $reservation instanceof ConnReservation || $reservation->node_id !== $caller->id
            || $reservation->conn_profile_id !== self::profileId($caller)) {
            return null;
        }

        return $reservation;
    }

    /** Keeps an open reservation alive for another lifetime; any other is left as it is. */
    public function touch(ConnReservation $reservation): ConnReservation
    {
        if ($reservation->state === ConnReservation::Open && $reservation->expires_at->isFuture()) {
            $reservation->update(['expires_at' => now()->addMinutes(self::LifetimeMinutes)]);
        }

        return $reservation->refresh();
    }

    /**
     * The pairing link a reserved clone's create issues for its device, or null when the create is no open reservation's. Its label
     * names the reservation, so a resent step asks for the same one.
     *
     * @return array{label: string, scopes: list<string>}|null
     */
    public function createPairing(Operation $operation): ?array
    {
        $reservation = self::opened()->where('create_operation_id', $operation->id)->whereNull('conn_pairing_id')->first();
        $label = $reservation === null ? null : self::pairingLabel($reservation);

        return $label === null ? null : ['label' => $label, 'scopes' => IssueConnPairingAction::Scopes];
    }

    /**
     * Records the pairing link a reserved clone's create issued, inside the create's projection, as `mintPairing` records a minted one:
     * only for the reservation's device under its profile, while it reaches the server. A link T3 refused, or one nobody may claim,
     * is not kept: the unused link expires on the server, and `schedulePairing` mints one when the reservation still wants it.
     *
     * @param  array<string, mixed>  $answer  T3's `{status, body}`
     */
    public function recordPairing(Instance $instance, ConnEnvironment $environment, #[\SensitiveParameter] array $answer): void
    {
        $reservation = self::opened()->where('instance_id', $instance->id)->whereNull('conn_pairing_id')->where('expires_at', '>', now())
            ->lockForUpdate()->first();
        if (! $reservation instanceof ConnReservation) {
            return;
        }
        $body = json_decode(is_string($answer['body'] ?? null) ? $answer['body'] : '', true);
        $status = $answer['status'] ?? null;
        if (! is_int($status) || $status < 200 || $status >= 300 || ! is_array($body)
            || ! is_string($body['id'] ?? null) || preg_match('/\A[a-zA-Z0-9_-]{1,1024}\z/D', $body['id']) !== 1
            || ! is_string($body['credential'] ?? null) || $body['credential'] === '' || strlen($body['credential']) > 16384
            || ! is_string($body['expiresAt'] ?? null)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/D', $body['expiresAt']) !== 1
            || ($body['label'] ?? null) !== self::pairingLabel($reservation)) {
            Log::info('conn.reservation_create_pairing_refused', ['reservation_id' => $reservation->id, 'status' => is_int($status) ? $status : null]);

            return;
        }
        $caller = Node::query()->whereKey($reservation->node_id)->where('status', LifecycleStatus::Active->value)->first();
        if (! $caller instanceof Node || self::profileId($caller) !== $reservation->conn_profile_id
            || ! app(ConnServerAccess::class)->allows($caller, $environment)) {
            return;
        }
        $pairing = ConnPairing::query()->create(['node_id' => $caller->id, 'conn_environment_id' => $environment->id, 'pairing_link_id' => $body['id'],
            'client_label' => $body['label'], 'expires_at' => Carbon::parse($body['expiresAt']), 'credential' => $body['credential'], 'issue_started_at' => now()]);
        $reservation->update(['conn_pairing_id' => $pairing->id]);
    }

    /**
     * Queues background minting of a one-time pairing link once a reserved clone's server session is recorded, so `ready` never waits
     * on it. A failure to queue logs and leaves `pairing` null, so the client falls back to explicit pairing.
     */
    public function schedulePairing(Instance $instance, ConnEnvironment $environment): void
    {
        $reservation = self::opened()->where('instance_id', $instance->id)->whereNull('conn_pairing_id')->where('expires_at', '>', now())->first();
        if (! $reservation instanceof ConnReservation) {
            return;
        }
        try {
            Bus::dispatch(new MintConnReservationPairing($reservation->id, $environment->id));
        } catch (Throwable $error) {
            Log::warning('conn.reservation_pairing_enqueue_failed', ['reservation_id' => $reservation->id, 'environment_id' => $environment->environment_id,
                'exception_type' => $error::class]);
        }
    }

    /**
     * Admits `conn.pairing_create` for a ready reserved clone, bound to its reserving caller and profile. Skips when the reservation
     * already ended, changed profile or lost server access.
     */
    public function mintPairing(string $reservationId, int $environmentId): void
    {
        $reservation = ConnReservation::query()->find($reservationId);
        $environment = ConnEnvironment::query()->find($environmentId);
        if (! $reservation instanceof ConnReservation || ! $environment instanceof ConnEnvironment
            || $reservation->state !== ConnReservation::Open || ! $reservation->expires_at->isFuture()
            || $reservation->conn_pairing_id !== null || $reservation->instance_id !== $environment->instance_id) {
            return;
        }
        $caller = Node::query()->whereKey($reservation->node_id)->where('status', LifecycleStatus::Active->value)->first();
        if (! $caller instanceof Node || self::profileId($caller) !== $reservation->conn_profile_id
            || ! app(ConnServerAccess::class)->allows($caller, $environment)) {
            return;
        }
        try {
            $operation = app(OperationIdempotency::class)->suspended(
                fn (): Operation => app(IssueConnPairingAction::class)->admit($caller, $environment));
        } catch (ResourceOperationException $error) {
            Log::info('conn.reservation_pairing_deferred', ['reservation_id' => $reservation->id, 'environment_id' => $environment->environment_id,
                'error_code' => $error->errorCode]);

            return;
        } catch (Throwable $error) {
            report($error);

            return;
        }
        DB::transaction(static function () use ($reservationId, $operation): void {
            $locked = ConnReservation::query()->lockForUpdate()->find($reservationId);
            if ($locked instanceof ConnReservation && $locked->state === ConnReservation::Open
                && $locked->expires_at->isFuture() && $locked->conn_pairing_id === null) {
                $locked->update(['conn_pairing_id' => $operation->target_id]);

                return;
            }
            ConnPairing::query()->whereKey($operation->target_id)->update(['credential' => null]);
        });
    }

    /**
     * Hands out the reservation's minted pairing link once for `GET /reservations/{id}` when the reservation is open, unexpired and
     * still matches its caller and profile. Its expiry is capped at the reservation's `expires_at`, and the stored credential is
     * cleared on read. Returns null while preparing, while minting still runs, after already claimed or after expiry.
     */
    public function claimPairing(ConnReservation $reservation, Node $caller): ?IssuedConnPairingData
    {
        if ($reservation->state !== ConnReservation::Open || ! $reservation->expires_at->isFuture()
            || $reservation->conn_pairing_id === null || $reservation->instance_id === null
            || $reservation->node_id !== $caller->id || self::profileId($caller) !== $reservation->conn_profile_id) {
            return null;
        }
        $environment = ConnEnvironment::query()->where('instance_id', $reservation->instance_id)->first();
        if (! $environment instanceof ConnEnvironment) {
            return null;
        }
        $pairingId = $reservation->conn_pairing_id;
        $reservationExpiresAt = $reservation->expires_at;

        return DB::transaction(static function () use ($pairingId, $environment, $caller, $reservationExpiresAt): ?IssuedConnPairingData {
            $pairing = ConnPairing::query()->where('node_id', $caller->id)->where('conn_environment_id', $environment->id)
                ->lockForUpdate()->find($pairingId);
            if (! $pairing instanceof ConnPairing || $pairing->credential === null || $pairing->revoked_at !== null) {
                return null;
            }
            if (! $pairing->expires_at?->isFuture() || ! $reservationExpiresAt->isFuture()) {
                $pairing->update(['credential' => null]);

                return null;
            }
            // A minted link is the reservation's once its mint succeeded; one its create issued has no mint.
            $mint = Operation::query()->where('kind', 'conn.pairing_create')->where('target_type', 'conn_pairing')->where('target_id', $pairing->id);
            if ((clone $mint)->exists() && ! $mint->where('status', OperationStatus::Succeeded)->exists()) {
                return null;
            }
            $expiresAt = $pairing->expires_at->gt($reservationExpiresAt) ? $reservationExpiresAt : $pairing->expires_at;
            $url = T3PairingUrl::build($environment->url, $pairing->credential);
            $pairing->update(['credential' => null, 'expires_at' => $expiresAt]);
            $pairing->setRelation('environment', $environment);
            $pairing->setRelation('node', $caller);

            return IssuedConnPairingData::fromModel($pairing, $url);
        });
    }

    /**
     * Gives the reserved clone its name and, optionally, its branch: admits `instance.finalize` once its create succeeded. The remote
     * read of a branch selection runs before the reservation's lock, as a create's does.
     */
    public function finalize(ConnReservation $reservation, Node $caller, string $name, ?InstanceBranchSelection $selection, ?string $requestId): Operation
    {
        $project = Project::query()->findOrFail($reservation->project_id);
        if ($selection !== null) {
            $selection = app(DevelopmentInstanceBranchAdmission::class)->prepare($project, $name, $selection, 'node:'.$caller->id);
        }

        return DB::transaction(function () use ($reservation, $caller, $name, $selection, $requestId): Operation {
            // A repeat with the request's Idempotency-Key answers the finalize it admitted.
            if (($replay = app(OperationIdempotency::class)->replay(InstanceFinalize::Kind)) !== null) {
                return $replay;
            }
            $reservation = ConnReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($reservation->state === ConnReservation::Finalized) {
                throw new ResourceOperationException('reservation.finalized', 'The reservation already took its name.', 409,
                    details: ['operation_id' => $reservation->finalize_operation_id]);
            }
            if ($reservation->state !== ConnReservation::Open || ! $reservation->expires_at->isFuture() || $reservation->instance_id === null) {
                throw new ResourceOperationException('reservation.expired', 'The reservation expired; start a new one or create the clone.', 410);
            }
            $create = Operation::query()->find($reservation->create_operation_id);
            if ($create?->status !== OperationStatus::Succeeded) {
                throw new ResourceOperationException($create?->status->terminal() === true ? 'reservation.failed' : 'reservation.not_ready',
                    $create?->status->terminal() === true ? 'The reserved clone did not start.' : 'The reserved clone still starts; finalize when it is ready.', 409,
                    details: ['create_operation_id' => $reservation->create_operation_id]);
            }
            $instance = Instance::query()->findOrFail($reservation->instance_id);
            $operation = app(InstanceFinalize::class)->admit($instance, $name, $selection, 'node:'.$caller->id, $requestId);
            $reservation->update(['state' => ConnReservation::Finalized, 'finalize_operation_id' => $operation->id]);

            return $operation;
        });
    }

    /**
     * Ends an open reservation as released or expired, clears any unread pairing credential, and destroys its clone, as Conn's removal
     * does with a running completion. A reservation that was finalized or ended meanwhile stays as it is: the row lock decides. A
     * refused destroy leaves it open, so the next pass tries again.
     */
    public function release(ConnReservation $reservation, string $state): ?Operation
    {
        return DB::transaction(function () use ($reservation, $state): ?Operation {
            $reservation = ConnReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($reservation->state !== ConnReservation::Open) {
                return null;
            }
            if ($reservation->conn_pairing_id !== null) {
                ConnPairing::query()->whereKey($reservation->conn_pairing_id)->update(['credential' => null]);
            }
            $instance = $reservation->instance_id === null ? null : Instance::query()->find($reservation->instance_id);
            $destroy = $instance === null ? null : app(OperationIdempotency::class)->suspended(fn (): Operation => app(StartInstanceDestroyAction::class)
                ->execute($instance, true, self::Caller, null, cancelCompletion: true));
            $reservation->update(['state' => $state, 'destroy_operation_id' => $destroy?->id]);

            return $destroy;
        });
    }

    /** The reconciler's pass: each open reservation past its lifetime is destroyed. */
    public function sweep(): void
    {
        foreach (self::opened()->where('expires_at', '<=', now())->get() as $reservation) {
            try {
                $this->release($reservation, ConnReservation::Expired);
            } catch (Throwable $error) {
                report($error);
            }
        }
    }

    /** Whether an open reservation holds the Instance: the idle sleep leaves it awake while its user types. */
    public static function holds(Instance $instance): bool
    {
        return self::opened()->where('instance_id', $instance->id)->where('expires_at', '>', now())->exists();
    }

    /**
     * The open reservations of these Instances, by Instance id, for the server list.
     *
     * @param  list<mixed>  $instanceIds
     * @return array<int, string>
     */
    public static function open(array $instanceIds): array
    {
        $ids = [];
        foreach (self::opened()->whereIn('instance_id', $instanceIds)->get(['id', 'instance_id']) as $reservation) {
            $ids[(int) $reservation->instance_id] = $reservation->id;
        }

        return $ids;
    }

    /** The reservation as the device reads it (docs/reference/conn.md#reserve-a-clone): its state follows its create and its finalize. */
    public static function snapshot(ConnReservation $reservation, ?IssuedConnPairingData $pairing = null): ConnReservationData
    {
        $create = $reservation->create_operation_id === null ? null : Operation::query()->find($reservation->create_operation_id);
        $finalize = $reservation->finalize_operation_id === null ? null : Operation::query()->find($reservation->finalize_operation_id);
        [$state, $error] = match ($reservation->state) {
            ConnReservation::Open => match (true) {
                ! $reservation->expires_at->isFuture() => ['expired', null],
                $create?->status === OperationStatus::Succeeded => ['ready', null],
                $create !== null && $create->status->terminal() => ['failed', $create->error_code],
                default => ['preparing', null],
            },
            ConnReservation::Finalized => match ($finalize?->status) {
                OperationStatus::Succeeded => ['finalized', null],
                OperationStatus::Failed, OperationStatus::Cancelled, OperationStatus::Interrupted => ['failed', $finalize->error_code],
                default => ['finalizing', null],
            },
            default => [$reservation->state, null],
        };
        if ($state === 'expired' && $reservation->conn_pairing_id !== null) {
            ConnPairing::query()->whereKey($reservation->conn_pairing_id)->whereNotNull('credential')->update(['credential' => null]);
        }
        $environment = $reservation->instance_id === null || ! in_array($state, ['ready', 'finalizing', 'finalized'], true) ? null
            : ConnEnvironment::query()->where('instance_id', $reservation->instance_id)->value('environment_id');

        return new ConnReservationData(
            id: $reservation->id,
            projectId: $reservation->project_id,
            instanceId: $reservation->instance_id,
            state: $state,
            expiresAt: $reservation->expires_at->toIso8601String(),
            createOperationId: $reservation->create_operation_id,
            environmentId: is_string($environment) ? $environment : null,
            finalizeOperationId: $reservation->finalize_operation_id,
            destroyOperationId: $reservation->destroy_operation_id,
            errorCode: $error,
            pairing: $state === 'ready' ? $pairing : null,
        );
    }

    /** The label of a reservation's pairing link from its create: the reserving Node's name and the reservation. */
    private static function pairingLabel(ConnReservation $reservation): ?string
    {
        $name = Node::query()->whereKey($reservation->node_id)->value('name');

        return is_string($name) ? $name.' via Orbit '.substr(str_replace('-', '', $reservation->id), 0, 8) : null;
    }

    /** The Node's current Conn profile id, or null when the Node has no binding. */
    private static function profileId(Node $node): ?int
    {
        $id = ConnProfileBinding::query()->where('node_id', $node->id)->value('conn_profile_id');

        return is_numeric($id) ? (int) $id : null;
    }

    /** A clone whose create takes a database, a Valkey user or a Route by its name cannot take another name later. */
    private static function assertSupported(Operation $operation): void
    {
        $plan = ValidatedData::object($operation->input['direct_provisioning'] ?? []);
        $reason = match (true) {
            ($plan['database'] ?? null) !== null || ($plan['server_database'] ?? null) !== null => 'database',
            ($plan['server_valkey'] ?? null) !== null => 'valkey',
            ($plan['route'] ?? null) !== null || ($operation->input['route_id'] ?? null) !== null => 'route',
            default => null,
        };
        if ($reason !== null) {
            throw new ResourceOperationException('reservation.unsupported', 'The Project\'s clones take their name at their create; create the clone on submit.', 409,
                details: ['reason' => $reason]);
        }
    }

    /** @return Builder<ConnReservation> */
    private static function opened(): Builder
    {
        return ConnReservation::query()->where('state', ConnReservation::Open);
    }
}
