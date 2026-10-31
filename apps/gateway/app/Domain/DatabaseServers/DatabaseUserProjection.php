<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseUser;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Safe admitted identity and record projection; the Node owns all SQL execution. */
final readonly class DatabaseUserProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $intent = $this->intent($operation);
        $command = $intent->payload['commands']['database-apply'];
        $input = $operation->input;
        $container = $command['input']['container'] ?? null;
        $pin = $input['agent_sha256'] ?? null;
        $username = $input['username'] ?? null;
        if (! in_array($input['driver'] ?? null, ['mysql', 'pgsql'], true)
            || ! is_string($container) || preg_match('/\Aorbit-process-[1-9][0-9]*-[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $container) !== 1
            || ! is_string($pin) || preg_match('/\A[0-9a-f]{64}\z/D', $pin) !== 1
            || ! is_string($username) || preg_match('/\A[A-Za-z0-9_]{1,32}\z/D', $username) !== 1
            || ! is_bool($input['replication'] ?? null) || ! is_bool($input['known_user'] ?? null)) {
            $this->invalid();
        }

        return ['driver' => $input['driver'], 'container' => $container, 'agent_sha256' => $pin,
            'username' => $username, 'replication' => $input['replication'], 'known_user' => $input['known_user']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $intent = $this->intent($operation);
        $user = $intent->payload['projection']['upsert_user'] ?? null;
        if ($user === null) {
            $this->invalid();
        }
        $raw = ValidatedData::object($data['result'] ?? []);
        $result = array_intersect_key($raw, array_flip(['backend', 'error_code']));
        $status = ValidatedData::string($data['status']);
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        if (($status === 'succeeded' && ($result !== ['backend' => 'active'] || $code !== null))
            || (in_array($status, ['running', 'cancelled'], true) && ($result !== [] || $code !== null))
            || ($status === 'failed' && ($code === null || $result !== ['error_code' => $code]
                || ! in_array($code, ['agent.version_mismatch', 'database.server_admin_invalid', 'database.server_admin_failed', 'database.server_not_ready', 'database.wal_level_not_logical', 'database.name_conflict'], true)))) {
            throw new ResourceOperationException('operation.result_invalid', 'The SQL user needs its bounded Swamp result.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $result, $status, $user): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result, 'database-apply');
            if ($status === 'succeeded' && ! $terminal) {
                DatabaseUser::query()->updateOrCreate(['database_connection_id' => $user['database_connection_id'], 'username' => $user['username']], $user);
            }

            return $projected;
        });
    }

    private function intent(Operation $operation): DatabaseExecutionIntent
    {
        $source = app(DatabaseIntentSource::class);
        $id = $source->ownerId($operation);
        if ($operation->kind !== 'database_user.create' || $operation->target_type !== 'database_server' || $id === null) {
            $this->invalid();
        }
        $intent = DatabaseExecutionIntent::recorded($id);
        $command = $intent->payload['commands']['database-apply'] ?? null;
        $user = $intent->payload['projection']['upsert_user'] ?? null;
        if (! is_array($command) || count($intent->payload['commands']) !== 1 || $command['name'] !== 'database.server-admin'
            || ($command['input']['operation'] ?? null) !== 'execute' || ($command['protected'] ?? []) !== ['password', 'sql']
            || ! is_array($user) || $user['database_connection_id'] !== ($operation->input['connection_id'] ?? null)
            || $user['username'] !== ($operation->input['username'] ?? null)
            || ! DatabaseConnection::query()->whereKey($user['database_connection_id'])->where('database_server_id', $operation->target_id)->exists()
            || ($intent->payload['projection']['children'] ?? []) !== []) {
            $this->invalid();
        }

        return $intent;
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('database.user_intent_invalid', 'The SQL user does not match its admitted database.', 422);
    }
}
