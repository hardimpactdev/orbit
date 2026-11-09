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
use Orbit\Extensions\Conn\Models\ConnPairing;

/** Shared terminal delivery imports the native protected output into the existing owner record. */
final readonly class ConnPairingProjection implements DirectOperationProjection
{
    /** T3 answered with one of these, so no retry issues a link. Any other failure keeps the outcome unknown. */
    public const array AnsweredFailures = ['conn.session_rejected', 'conn.request_refused', 'conn.response_invalid'];

    public function __construct(private DirectOperation $direct, private SwampVaultExecutionCopy $vault, private ConnPairingCredentialStore $store, private SwampScheduleWorkflows $mutations) {}

    public function input(Operation $operation): array
    {
        return Arr::only($operation->input, ['intent', 'execution_secrets']);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
            $native = ValidatedData::object($data['result'] ?? []);
            $raw = ValidatedData::object(json_decode(ValidatedData::string($native['issued_receipt'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR));
            if (in_array($data['status'] ?? null, ['failed', 'interrupted'], true)) {
                $failure = ValidatedData::object($data['failure'] ?? []);
                $detail = $failure['detail'] ?? null;
                $code = in_array($detail, self::AnsweredFailures, true) ? $detail : 'conn.pairing_outcome_unknown';
                $data['error_code'] = $code;
                $raw = ['error_code' => $code];
            }
            $result = Arr::only($raw, ['backend', 'pairing_id', 'pairing_link_id', 'expires_at', 'error_code']);
            if (! $operation->status->terminal() && ($data['status'] ?? null) === 'succeeded') {
                if ($this->store->ownerId($operation) !== $operation->target_id || ($raw['backend'] ?? null) !== 'issued'
                    || ($raw['pairing_id'] ?? null) !== $operation->target_id) {
                    throw new ResourceOperationException('operation.execution_forbidden', 'The pairing output has another owner.', 422);
                }
                $reference = ValidatedData::object($raw['credential_ref'] ?? []);
                if (($reference['vault'] ?? null) !== 'orbit-control-inputs' || ! is_string($reference['key'] ?? null)
                    || preg_match('/\Anckrtl-conn-pairing-[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}-issue-issued-pairing-'.preg_quote((string) $operation->target_id, '/').'-credential\z/D', $reference['key']) !== 1) {
                    throw new ResourceOperationException('conn.pairing_credential_invalid', 'The pairing output needs its native vault reference.', 422);
                }
                $pairing = ConnPairing::query()->lockForUpdate()->findOrFail($operation->target_id);
                $pairing->forceFill(['pairing_link_id' => ValidatedData::string($raw['pairing_link_id']),
                    'expires_at' => Carbon::parse(ValidatedData::string($raw['expires_at'])),
                    'issue_started_at' => $pairing->issue_started_at ?? Carbon::parse(ValidatedData::string($data['started_at']))])->save();
                $this->store->store($operation, $pairing->id, $this->vault->readSecret($reference['vault'], $reference['key']));
                $this->mutations->deleteSecret($reference['vault'], $reference['key']);
            }

            $data['result'] = $result;

            return $this->direct->project($operation, $data, $result);
        });
    }
}
