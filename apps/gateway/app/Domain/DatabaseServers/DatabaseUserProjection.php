<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\DatabaseUser;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** The admitted user as rendered batches behind stdin logins, and the user record from the native result. */
final readonly class DatabaseUserProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $intent = $this->intent($operation);
        $input = $intent->payload['commands']['database-apply']['input'];
        $container = $input['container'] ?? null;
        $username = $input['username'] ?? null;
        if (! in_array($input['driver'] ?? null, ['mysql', 'pgsql'], true)
            || ! is_string($container) || preg_match('/\Aorbit-process-[1-9][0-9]*-[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $container) !== 1
            || ! is_string($username) || preg_match('/\A[A-Za-z0-9_]{1,32}\z/D', $username) !== 1
            || ! is_string($input['database'] ?? null) || preg_match('/\A[A-Za-z0-9_]{1,'.($input['driver'] === 'pgsql' ? '63' : '64').'}\z/D', $input['database']) !== 1
            || ! is_string($input['owner_username'] ?? null) || preg_match('/\A[A-Za-z0-9_]{1,63}\z/D', $input['owner_username']) !== 1
            || ! is_bool($input['read_only'] ?? null)
            || ! is_bool($input['replication'] ?? null) || ! is_bool($input['known_user'] ?? null)) {
            $this->invalid();
        }

        $driver = DatabaseDriver::from($input['driver']);
        $payload = ValidatedData::object($input['payload'] ?? null);
        $password = ValidatedData::string($payload['password'] ?? null);
        $statements = app(DatabaseStatements::class)->user(['driver' => $driver->value, 'username' => $username, 'database' => $input['database'],
            'owner_username' => $input['owner_username'], 'read_only' => $input['read_only'], 'replication' => $input['replication'],
            'known_user' => $input['known_user']], $password, ValidatedData::string($payload['salt'] ?? null));
        $secrets = [];
        foreach (['apply', 'check'] as $batch) {
            $secrets[$batch] = ['vault' => 'orbit-control-inputs', 'key' => "database-user-create-{$batch}-{$intent->id}"];
        }
        if (! $operation->status->terminal()) {
            // Each login reads its password from the first stdin line: the admin's for the server work, the user's own for
            // the check (Q-DBAUTH). The apply batch carries the user's password too.
            $root = DatabaseServer::query()->findOrFail($operation->target_id)->root_password;
            $copy = app(SwampCommandStep::class);
            $secrets['apply'] = $copy->executionCopy('orbit-control-inputs', $secrets['apply']['key'], "{$root}\n{$statements['apply']}");
            $secrets['check'] = $copy->executionCopy('orbit-control-inputs', $secrets['check']['key'], "{$password}\n{$statements['check']}");
        }
        [$admin, $adminDatabase] = $driver === DatabaseDriver::Pgsql ? ['postgres', 'postgres'] : ['root', 'mysql'];

        return ['intent' => ['driver' => $driver->value, 'username' => $username, 'replication' => $input['replication'],
            'admin_client' => DatabaseStatements::login($driver, $container, $admin, $adminDatabase),
            'user_client' => DatabaseStatements::login($driver, $container, $username, $input['database']),
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($operation->node_id))],
            'execution_secrets' => $secrets];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $intent = $this->intent($operation);
        $user = $intent->payload['projection']['upsert_user'] ?? null;
        if ($user === null) {
            $this->invalid();
        }
        [$data, $result] = $this->outcome($operation, $data);
        $status = ValidatedData::string($data['status']);

        return DB::transaction(function () use ($operation, $data, $result, $status, $user): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($status === 'succeeded' && ! $terminal) {
                DatabaseUser::query()->updateOrCreate(['database_connection_id' => $user['database_connection_id'], 'username' => $user['username']], $user);
            }

            return $projected;
        });
    }

    /**
     * The native result decides: a WAL level other than logical or a foreign role (the batch's markers), a failed
     * batch, or a user that cannot log in with its own password fails the run with its code.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function outcome(Operation $operation, array $data): array
    {
        if (($data['status'] ?? null) !== 'succeeded') {
            return [$data, ['error_code' => ValidatedData::nullableString($data['error_code'] ?? null) ?? 'database.server_admin_failed']];
        }
        $steps = ValidatedData::object(ValidatedData::object($data['result'] ?? [])['steps'] ?? []);
        $read = static function (string $step) use ($steps): array {
            $entry = ValidatedData::object($steps[$step] ?? []);

            return [is_int($entry['exit_code'] ?? null) ? $entry['exit_code'] : null,
                array_values(array_filter(array_map(trim(...), explode("\n", ValidatedData::nullableString($entry['stdout'] ?? null) ?? ''))))];
        };
        $input = $this->intent($operation)->payload['commands']['database-apply']['input'];
        [$applyExit, $applyLines] = $read('Apply login and grants');
        [$checkExit, $checkLines] = $read('Check the user');
        $user = ValidatedData::string($input['username'] ?? null);
        $expected = $input['driver'] === 'mysql' ? $user.'@%' : $user;
        [$step, $code] = match (true) {
            in_array('database.wal_level_not_logical', $applyLines, true) => ['Apply login and grants', 'database.wal_level_not_logical'],
            in_array('database.name_conflict', $applyLines, true) => ['Apply login and grants', 'database.name_conflict'],
            $applyExit !== 0 => ['Apply login and grants', 'database.server_admin_failed'],
            $checkExit !== 0 || ($checkLines[0] ?? null) !== $expected => ['Check the user', 'database.user_login_failed'],
            default => [null, null],
        };
        if ($code === null) {
            return [$data, ['backend' => 'active']];
        }

        return [[...$data, 'status' => 'failed', 'error_code' => $code, 'failure' => ['failed_step' => $step, 'stderr_recorded' => true]], ['error_code' => $code]];
    }

    private function intent(Operation $operation): DatabaseExecutionIntent
    {
        $source = app(DatabaseIntentSource::class);
        $id = $source->ownerId($operation);
        if ($operation->kind !== 'database_user.create' || $operation->target_type !== 'database_server' || $id === null) {
            $this->invalid();
        }
        $intent = DatabaseExecutionIntent::recorded($id);

        return $intent;
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('database.user_intent_invalid', 'The SQL user does not match its admitted database.', 422);
    }
}
