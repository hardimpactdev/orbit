<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampScheduleWorkflows;
use App\Infrastructure\Swamp\SwampVaultExecutionCopy;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Orbit\Extensions\Conn\Actions\AdmitConnCleanupAction;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use stdClass;
use Throwable;

/**
 * Records a renewal of a container server's Gateway session from the run's one public report. A success stores the new session,
 * which the run proved with one authenticated read, and only then admits the cleanup that revokes the older `Orbit Gateway`
 * sessions. A refusal keeps the old session and records its `conn.*` code on the server, where the next sweep reads it.
 */
final readonly class ConnRenewalProjection implements DirectOperationProjection
{
    /** The vault key the `renew` step of `@nckrtl/instance-t3` writes for one Instance. */
    public const string SessionKey = '^nckrtl-instance-t3-[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}-renew-session-gateway-[1-9][0-9]*-token$';

    public function __construct(private DirectOperation $direct, private SwampVaultExecutionCopy $vault, private SwampScheduleWorkflows $mutations) {}

    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['intent']), 'execution_secrets' => new stdClass];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = ValidatedData::string($data['status']);
        $raw = ValidatedData::object($data['result'] ?? []);
        $session = null;
        $reference = null;
        $result = [];
        if ($status === 'succeeded') {
            $reference = $this->reference($operation, $raw);
            // The vault read is network I/O, so it stays outside the result transaction.
            $session = $this->vault->readSecret('orbit-control-inputs', $reference);
            if ($session === '') {
                throw new ResourceOperationException('operation.result_invalid', 'The renewed session is missing from the vault.', 422);
            }
            $result = ['environment_id' => ValidatedData::string($raw['environment_id']), 'admin_session_expires_at' => Carbon::parse(ValidatedData::string($raw['expires_at']))->toIso8601String()];
        } elseif (in_array($status, ['failed', 'interrupted'], true)) {
            $code = $raw['error_code'] ?? null;
            $code = is_string($code) && preg_match('/\Aconn\.[a-z_]{1,80}\z/D', $code) === 1 ? $code : 'conn.renewal_failed';
            $data['error_code'] = $code;
            $result = ['error_code' => $code];
        }
        $data['result'] = $result;

        $recorded = null;
        $projected = DB::transaction(function () use ($operation, $data, $result, $raw, $session, &$recorded): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
            $wasTerminal = $operation->status->terminal();
            $projected = $this->direct->project($operation, $data, $result);
            $environment = ConnEnvironment::query()->lockForUpdate()->find($operation->target_id);
            if ($wasTerminal || $environment === null || $environment->instance_id !== $operation->input['instance_id']) {
                return $projected;
            }
            if ($session !== null) {
                $environment->fill(['admin_session' => $session, 'admin_session_expires_at' => Carbon::parse(ValidatedData::string($raw['expires_at'])),
                    'server_version' => ValidatedData::string($raw['server_version']), 'registered_at' => Carbon::now(),
                    'cleanup_version' => $environment->cleanup_version + 1, 'renewal_error_code' => null, 'renewal_checked_at' => Carbon::now()])->save();
                $recorded = $environment;
            } elseif (isset($result['error_code'])) {
                $environment->fill(['renewal_error_code' => $result['error_code'], 'renewal_checked_at' => Carbon::now()])->save();
            }

            return $projected;
        });

        if ($reference !== null) {
            try {
                $this->mutations->deleteSecret('orbit-control-inputs', $reference);
            } catch (Throwable $error) {
                Log::warning('conn.renewal_vault_cleanup_failed', ['operation_id' => $operation->id, 'exception' => $error::class]);
            }
        }
        if ($recorded instanceof ConnEnvironment) {
            try {
                app(AdmitConnCleanupAction::class)->execute($recorded, Node::query()->findOrFail($recorded->node_id));
            } catch (ResourceOperationException $error) {
                // A pairing that still uses the old session holds the cleanup; the next renewal sweep admits it.
                Log::info('conn.cleanup_deferred', ['environment_id' => $recorded->environment_id, 'error_code' => $error->errorCode]);
            }
        }

        return $projected;
    }

    /** @param array<string,mixed> $raw */
    private function reference(Operation $operation, array $raw): string
    {
        $intent = ValidatedData::object($operation->input['intent']);
        $session = $raw['gateway_session'] ?? null;
        $key = is_array($session) ? ($session['key'] ?? null) : null;
        $expires = $raw['expires_at'] ?? null;
        if (($raw['outcome'] ?? null) !== 'succeeded' || ! is_array($session) || ($session['vault'] ?? null) !== 'orbit-control-inputs' || ! is_string($key)
            || preg_match('/'.self::SessionKey.'/D', $key) !== 1 || ! str_ends_with($key, '-gateway-'.ValidatedData::integer($intent['instance_id']).'-token')
            || ($raw['environment_id'] ?? null) !== $intent['environment_id'] || ! is_string($raw['server_version'] ?? null)
            || ! is_string($expires) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/D', $expires) !== 1) {
            throw new ResourceOperationException('operation.result_invalid', 'The renewal report does not match its intent.', 422);
        }

        return $key;
    }
}
