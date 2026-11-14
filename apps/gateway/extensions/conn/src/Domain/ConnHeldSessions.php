<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Orbit\Extensions\Conn\Actions\AdmitConnSessionHoldAction;
use Orbit\Extensions\Conn\Actions\RevokeConnPairingsAction;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Orbit\Extensions\Conn\Models\ConnProfileBinding;

/**
 * The sessions the Gateway holds for Nodes on container T3 servers ([Held sessions](/reference/conn#held-sessions)). A held session is a
 * held pairing: the Gateway minted its link and redeemed it itself. A Node reads its current one; the Gateway renews it, withdraws it
 * when it must end, and owes its revoke on the server until a revoke by its pairing id has covered it.
 */
final readonly class ConnHeldSessions
{
    public const string Kind = 'conn.session_hold';

    public function __construct(private ConnServerAccess $access) {}

    /** The session the Node may use now: held, not withdrawn or revoked, and not ended. */
    public function current(Node $node, ConnEnvironment $environment): ?ConnPairing
    {
        return ConnPairing::query()->where('node_id', $node->id)->where('conn_environment_id', $environment->id)->where('held', true)
            ->whereNotNull('session')->whereNull('withdrawn_at')->whereNull('revoked_at')->where('session_expires_at', '>', Carbon::now())
            ->latest('id')->first();
    }

    /**
     * Stops serving a held session and records that its revoke is owed. The caller holds the transaction; the revoke is admitted after
     * its commit, and the hourly sweep admits it again while the server cannot take it.
     */
    public function withdraw(ConnPairing $pairing): void
    {
        $pairing->forceFill(['session' => null, 'withdrawn_at' => $pairing->withdrawn_at ?? Carbon::now()])->save();
        DB::afterCommit(fn () => $this->revoke($pairing));
    }

    /** Admits the revoke of one withdrawn pairing by its id; a refusal waits for the next sweep. */
    public function revoke(ConnPairing $pairing): ?Operation
    {
        $environment = $pairing->environment;
        try {
            return app(RevokeConnPairingsAction::class)->execute($pairing->node, $environment, $pairing->node, $pairing->id);
        } catch (ResourceOperationException $error) {
            Log::info('conn.held_revoke_deferred', ['environment_id' => $environment->environment_id, 'pairing_id' => $pairing->id, 'error_code' => $error->errorCode]);

            return null;
        }
    }

    /** Withdraws every held session of the Node on the servers it no longer reaches. */
    public function withdrawUnreachable(Node $node): void
    {
        DB::transaction(function () use ($node): void {
            $pairings = ConnPairing::query()->where('node_id', $node->id)->where('held', true)->whereNull('withdrawn_at')->whereNull('revoked_at')
                ->with('environment.node')->lockForUpdate()->get();
            foreach ($pairings as $pairing) {
                if (! $this->access->allows($node, $pairing->environment)) {
                    $this->withdraw($pairing);
                }
            }
        });
    }

    /** Renews each held session on the server that ends within the margin; a wake and a renewed Gateway session call it. */
    public function renewDue(ConnEnvironment $environment, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $due = ConnPairing::query()->where('conn_environment_id', $environment->id)->where('held', true)->whereNotNull('session')
            ->whereNull('withdrawn_at')->whereNull('revoked_at')->where('session_expires_at', '<', $now->addDays(ConnRenewalSweep::MarginDays))
            ->with(['node', 'environment.node'])->get();
        foreach ($due as $pairing) {
            // A Node that lost its access gets no new session; the sweep withdraws its old one.
            if ($this->access->allows($pairing->node, $pairing->environment)) {
                $this->renew($pairing);
            }
        }
    }

    public function renew(ConnPairing $pairing): ?Operation
    {
        try {
            return app(AdmitConnSessionHoldAction::class)->renew($pairing);
        } catch (ResourceOperationException $error) {
            Log::info('conn.held_renewal_deferred', ['pairing_id' => $pairing->id, 'error_code' => $error->errorCode]);

            return null;
        }
    }

    /** A new clone's server: hold a session for each Node of the creating Node's profile that reaches it, so the first attach is one read. */
    public function preMint(Instance $instance, ConnEnvironment $environment): void
    {
        $creator = Operation::query()->where('kind', 'instance.create')->where('target_type', 'instance')->where('target_id', $instance->id)
            ->oldest('id')->value('triggered_by');
        if (! is_string($creator) || preg_match('/\Anode:([1-9][0-9]*)\z/D', $creator, $match) !== 1) {
            return;
        }
        $profile = ConnProfileBinding::query()->where('node_id', (int) $match[1])->value('conn_profile_id');
        $ids = $profile === null ? [(int) $match[1]] : ConnProfileBinding::query()->where('conn_profile_id', $profile)->pluck('node_id')->all();
        foreach (Node::query()->whereIn('id', $ids)->orderBy('id')->get() as $node) {
            if (! $this->access->allows($node, $environment)) {
                continue;
            }
            try {
                app(AdmitConnSessionHoldAction::class)->hold($node, $environment);
            } catch (ResourceOperationException $error) {
                Log::info('conn.held_premint_deferred', ['environment_id' => $environment->environment_id, 'node_id' => $node->id, 'error_code' => $error->errorCode]);
            }
        }
    }

    /** Whether the hold that makes this pairing's session still runs. */
    public static function holding(int $pairing): bool
    {
        return Operation::query()->where('kind', self::Kind)->where('target_type', 'conn_pairing')->where('target_id', $pairing)
            ->whereNotIn('status', OperationStatus::terminalValues())->exists();
    }

    /**
     * A revoke's end settles the held pairings it named. One it covered no longer owes a revoke; an unmatched revoke of that one alone
     * proves nothing of it is left on the server, unless its hold ran at the revoke's admission: then the session may come after the
     * revoke read the server, so the pairing stays withdrawn and the hold's end revokes it again (issue 3540). Then the next owed revoke
     * on the server starts: one revoke holds a server at a time.
     */
    public function revoked(Operation $operation, OperationOutcome $outcome): void
    {
        $entries = ValidatedData::object($operation->input['intent'] ?? [])['pairings'] ?? [];
        $covered = array_map(static fn (mixed $entry): int => ValidatedData::integer(ValidatedData::object($entry)['id']), is_array($entries) ? array_values($entries) : []);
        $named = ValidatedData::integerList($operation->input['pairing_ids'] ?? []);
        $unmatched = $outcome->status !== OperationStatus::Succeeded && $outcome->errorCode === 'conn.revocation_unmatched';
        $holding = ValidatedData::integerList($operation->input['holding_pairing_ids'] ?? []);
        if ($outcome->status !== OperationStatus::Succeeded && ! $unmatched) {
            return;
        }
        DB::transaction(function () use ($operation, $covered, $named, $unmatched, $holding): void {
            $held = ConnPairing::query()->whereIn('id', $named)->where('conn_environment_id', $operation->input['conn_environment_id'])
                ->where('held', true)->lockForUpdate()->get();
            foreach ($held as $pairing) {
                if (in_array($pairing->id, $covered, true) && (! $unmatched || ($covered === [$pairing->id] && ! in_array($pairing->id, $holding, true)))) {
                    $pairing->forceFill(['session' => null, 'withdrawn_at' => null, 'revoked_at' => $pairing->revoked_at ?? Carbon::now()])->save();
                }
            }
            DB::afterCommit(fn () => $this->revokeNext(ValidatedData::integer($operation->input['conn_environment_id'])));
        });
    }

    /** Admits the revoke of the server's next owed held session, if any. One whose hold still runs waits: the hold's end revokes it. */
    public function revokeNext(int $environment): ?Operation
    {
        $owed = ConnPairing::query()->where('conn_environment_id', $environment)->where('held', true)->whereNotNull('withdrawn_at')
            ->where(static fn ($query) => $query->whereNull('session_expires_at')->orWhere('session_expires_at', '>', Carbon::now()))
            ->whereNotIn('id', Operation::query()->where('kind', self::Kind)->where('target_type', 'conn_pairing')
                ->whereNotIn('status', OperationStatus::terminalValues())->select('target_id'))->orderBy('id')->first();

        return $owed instanceof ConnPairing ? $this->revoke($owed) : null;
    }

    /** A withdrawn session past its end owes nothing: T3 refuses it already. One whose end is unknown ends with T3's 30-day session. */
    public function settleEnded(CarbonImmutable $now): void
    {
        ConnPairing::query()->where('held', true)->whereNotNull('withdrawn_at')
            ->where(static fn ($query) => $query->where('session_expires_at', '<=', $now)
                ->orWhere(static fn ($unknown) => $unknown->whereNull('session_expires_at')->where('created_at', '<=', $now->subDays(30))))
            ->update(['withdrawn_at' => null, 'revoked_at' => $now]);
    }
}
