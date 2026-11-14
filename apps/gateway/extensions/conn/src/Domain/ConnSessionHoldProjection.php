<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampScheduleWorkflows;
use App\Infrastructure\Swamp\SwampVaultExecutionCopy;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Throwable;

/**
 * Records a `conn.session_hold` run ([Held sessions](/reference/conn#held-sessions)). A success stores the session encrypted on its held
 * pairing and deletes the vault keys of the run; the result holds only public metadata. A session that a revoke or a renewal must end, or one of an
 * unknown outcome, is withdrawn instead, and its revoke by pairing id is owed.
 */
final readonly class ConnSessionHoldProjection implements DirectOperationProjection
{
    /** T3 answered with one of these, so no session exists. Any other failure may have left one. */
    public const array AnsweredFailures = ['conn.session_rejected', 'conn.request_refused', 'conn.response_invalid', 'conn.redeem_refused'];

    private const string KeyPattern = '/\Anckrtl-conn-session-[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}-%s-pairing-%d-%s\z/D';

    public function __construct(private DirectOperation $direct, private SwampVaultExecutionCopy $vault, private SwampScheduleWorkflows $mutations, private ConnHeldSessions $held) {}

    public function input(Operation $operation): array
    {
        return Arr::only($operation->input, ['intent', 'execution_secrets']);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = ValidatedData::string($data['status']);
        $captured = ValidatedData::object($data['result'] ?? []);
        $raw = $status === 'succeeded' ? ValidatedData::object(json_decode(ValidatedData::string(ValidatedData::object($data['result'] ?? [])['held_receipt'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR)) : [];
        $keys = [];
        $session = null;
        $replay = $operation->fresh()?->status->terminal() ?? true;
        if ($status === 'succeeded' && ! $replay) {
            $keys = $this->keys($operation, $raw);
            // The vault read is network I/O, so it stays outside the result transaction.
            $session = $this->vault->readSecret('orbit-control-inputs', $keys[0]);
            if ($session === '' || strlen($session) > 16384) {
                throw new ResourceOperationException('operation.result_invalid', 'The held session is missing from the vault.', 422);
            }
        }
        $result = [];
        if ($status === 'succeeded') {
            $result = Arr::only($raw, ['backend', 'pairing_id', 'pairing_link_id', 'session_expires_at']);
        } elseif (in_array($status, ['failed', 'interrupted'], true)) {
            $detail = ValidatedData::object($data['failure'] ?? [])['detail'] ?? null;
            $data['error_code'] = in_array($detail, self::AnsweredFailures, true) ? $detail : 'conn.session_outcome_unknown';
            $result = ['error_code' => $data['error_code']];
        }
        $data['result'] = $result;

        $projected = DB::transaction(function () use ($operation, $data, $result, $raw, $session, $status): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
            $wasTerminal = $operation->status->terminal();
            $projected = $this->direct->project($operation, $data, $result);
            // Not by its Node: a deleted Node's pairing passed to the Gateway Node, and its session must still be withdrawn.
            $pairing = ConnPairing::query()->where('held', true)
                ->where('conn_environment_id', $operation->input['conn_environment_id'])->lockForUpdate()->find($operation->target_id);
            if ($wasTerminal || ! $pairing instanceof ConnPairing) {
                return $projected;
            }
            $started = Carbon::parse(ValidatedData::string($data['started_at'] ?? Carbon::now()->toJSON()));
            if ($session !== null) {
                $pairing->forceFill(['pairing_link_id' => ValidatedData::string($raw['pairing_link_id']), 'expires_at' => Carbon::parse(ValidatedData::string($raw['link_expires_at'])),
                    'issue_started_at' => $pairing->issue_started_at ?? $started, 'session_expires_at' => Carbon::parse(ValidatedData::string($raw['session_expires_at']))])->save();
                // A revoke admitted while this run made the session read the server before it existed (issue 3540).
                if ($pairing->revoked_at !== null || $pairing->withdrawn_at !== null) {
                    $this->held->withdraw($pairing);
                } else {
                    $pairing->forceFill(['session' => $session])->save();
                }
                $renewed = $operation->input['renews_pairing_id'] ?? null;
                $previous = is_int($renewed) ? ConnPairing::query()->where('held', true)->whereNull('withdrawn_at')->whereNull('revoked_at')
                    ->where('node_id', $pairing->node_id)->where('conn_environment_id', $pairing->conn_environment_id)->lockForUpdate()->find($renewed) : null;
                if ($previous instanceof ConnPairing) {
                    $this->held->withdraw($previous);
                }
            } elseif ($status !== 'succeeded' && in_array($result['error_code'] ?? null, self::AnsweredFailures, true)) {
                // T3 answered, so no session exists: the pairing is settled and owes nothing, also when a revoke withdrew it meanwhile.
                $pairing->forceFill(['session' => null, 'withdrawn_at' => null, 'revoked_at' => $pairing->revoked_at ?? Carbon::now()])->save();
            } elseif ($status !== 'succeeded' && $pairing->revoked_at === null) {
                $pairing->forceFill(['issue_started_at' => $pairing->issue_started_at ?? $started])->save();
                $this->held->withdraw($pairing);
            }

            return $projected;
        });

        // The admin session's execution copy, a minted link and a stored session live for this run only, on every terminal path. The
        // session key is the link key's sibling, so a run that failed after it stored the session leaves nothing; a missing key is done.
        $copy = ValidatedData::object(ValidatedData::object($operation->input['execution_secrets'] ?? [])['admin_session'] ?? []);
        if (! $replay && in_array($status, ['succeeded', 'failed', 'interrupted', 'cancelled'], true)) {
            $link = $captured['link_key'] ?? null;
            if (is_string($link) && preg_match(sprintf(self::KeyPattern, 'link-link', $operation->target_id, 'credential'), $link) === 1) {
                $keys[] = $link;
                $keys[] = substr($link, 0, -strlen("-link-link-pairing-{$operation->target_id}-credential"))."-hold-held-pairing-{$operation->target_id}-access_token";
            }
            if (is_string($copy['key'] ?? null)) {
                $keys[] = $copy['key'];
            }
        }
        $keys = array_values(array_unique($keys));
        foreach ($keys as $key) {
            try {
                $this->mutations->deleteSecret('orbit-control-inputs', $key);
            } catch (Throwable $error) {
                Log::warning('conn.held_vault_cleanup_failed', ['operation_id' => $operation->id, 'exception' => $error::class]);
            }
        }

        return $projected;
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array{0: string, 1: string} the session key, then the link key
     */
    private function keys(Operation $operation, array $raw): array
    {
        $pairing = $operation->target_id;
        $session = ValidatedData::object($raw['session_ref'] ?? []);
        $link = ValidatedData::object($raw['link_ref'] ?? []);
        if (($raw['backend'] ?? null) !== 'held' || ($raw['pairing_id'] ?? null) !== $pairing || ! is_string($raw['pairing_link_id'] ?? null)
            || ! is_string($raw['link_expires_at'] ?? null) || ! is_string($raw['session_expires_at'] ?? null)
            || ($session['vault'] ?? null) !== 'orbit-control-inputs' || ($link['vault'] ?? null) !== 'orbit-control-inputs'
            || ! is_string($session['key'] ?? null) || preg_match(sprintf(self::KeyPattern, 'hold-held', $pairing, 'access_token'), $session['key']) !== 1
            || ! is_string($link['key'] ?? null) || preg_match(sprintf(self::KeyPattern, 'link-link', $pairing, 'credential'), $link['key']) !== 1) {
            throw new ResourceOperationException('operation.result_invalid', 'The held session report does not match its intent.', 422);
        }

        return [$session['key'], $link['key']];
    }
}
