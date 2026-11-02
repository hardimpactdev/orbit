<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Actions\DatabaseConnections\AttachDatabaseConnectionAction;
use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Public admitted names and record projection; Swamp owns every SQL decision. */
final readonly class DatabaseCreateProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $id = app(DatabaseIntentSource::class)->ownerId($operation);
        if ($id === null) {
            throw new ResourceOperationException('database.create_intent_invalid', 'The create belongs to another resource or Node.', 422);
        }
        $intent = DatabaseExecutionIntent::recorded($id);
        $input = ValidatedData::object($intent->payload['commands']['database-apply']['input']);
        $payload = ValidatedData::object($input['payload']);
        $driver = DatabaseDriver::from(ValidatedData::string($input['driver']));
        $user = ValidatedData::string($input['username']);
        $databases = ValidatedData::stringList($input['databases']);
        $statements = app(DatabaseStatements::class)->create(['driver' => $driver->value, 'username' => $user, 'databases' => $databases,
            'test_prefix' => ValidatedData::nullableString($input['test_prefix']), 'locale' => ValidatedData::nullableString($input['locale']),
            'index' => is_int($input['index'] ?? null) ? $input['index'] : null,
            'patterns' => ValidatedData::stringList($input['patterns']), 'allow' => ValidatedData::stringList($input['allow'])],
            ValidatedData::string($payload['password']), ValidatedData::string($payload['salt']));
        $container = ValidatedData::string($input['container']);
        $secrets = ['create' => ['vault' => 'orbit-control-inputs', 'key' => "database-create-intent-{$id}"],
            'check' => ['vault' => 'orbit-control-inputs', 'key' => "database-create-owner-{$id}"]];
        if (! $operation->status->terminal()) {
            // Each login reads its password from the first stdin line; the statements follow (Q-DBAUTH).
            $copy = app(SwampCommandStep::class);
            $secrets['create'] = $copy->executionCopy('orbit-control-inputs', $secrets['create']['key'], $this->server($operation)->root_password."\n".$statements['create']);
            $secrets['check'] = $copy->executionCopy('orbit-control-inputs', $secrets['check']['key'], $statements['owner_password']."\n".$statements['login']);
        }
        [$admin, $adminDatabase] = match ($driver) {
            DatabaseDriver::Pgsql => ['postgres', 'postgres'], DatabaseDriver::Redis => ['default', ''], default => ['root', 'mysql']
        };

        return ['intent' => ['driver' => $driver->value, 'username' => $user,
            'admin_client' => DatabaseStatements::login($driver, $container, $admin, $adminDatabase),
            'owner_client' => DatabaseStatements::login($driver, $container, $user, $databases[0] ?? ''),
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($operation->node_id))],
            'execution_secrets' => $secrets];
    }

    /**
     * The native result decides: a name the server holds for someone else, a failed batch, a Valkey index at or above
     * the live count, or an owner that cannot log in fails the run with its code.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function outcome(Operation $operation, array $data): array
    {
        if (($data['status'] ?? null) !== 'succeeded') {
            $code = ValidatedData::nullableString($data['error_code'] ?? null) ?? 'database.server_admin_failed';

            return [$data, ['error_code' => $code]];
        }
        $steps = ValidatedData::object(ValidatedData::object($data['result'] ?? [])['steps'] ?? []);
        $read = static function (string $step) use ($steps): array {
            $entry = ValidatedData::object($steps[$step] ?? []);

            return [is_int($entry['exit_code'] ?? null) ? $entry['exit_code'] : null,
                array_values(array_filter(array_map(trim(...), explode("\n", ValidatedData::nullableString($entry['stdout'] ?? null) ?? ''))))];
        };
        [$exit, $lines] = $read('Create owner and databases');
        $driver = ValidatedData::string($operation->input['driver']);
        $admitted = DatabaseExecutionIntent::recorded(ValidatedData::integer($operation->input['intent_id']))->payload['commands']['database-apply']['input'];
        $index = $admitted['index'] ?? null;
        $refused = static fn (array $lines): bool => array_any($lines, static fn (string $line): bool => str_starts_with($line, 'ERR') || str_starts_with($line, 'WRONGPASS') || str_starts_with($line, 'NOAUTH'));
        [$step, $code] = match (true) {
            in_array('database.name_conflict', $lines, true) => ['Create owner and databases', 'database.name_conflict'],
            $exit !== 0 || ($driver === 'redis' && $refused($lines)) => ['Create owner and databases', 'database.server_admin_failed'],
            $driver === 'redis' && is_int($index) && ($lines[0] ?? null) === 'databases' && ctype_digit($lines[1] ?? '') && $index >= (int) $lines[1] => ['Create owner and databases', 'database.index_invalid'],
            default => [null, null],
        };
        if ($code === null) {
            [$exit, $lines] = $read('Check the owner login');
            $user = ValidatedData::string($admitted['username'] ?? null);
            $expected = match ($driver) {
                'mysql' => $user.'@%', 'pgsql' => $user, default => null
            };
            // A Valkey owner without +ping answers NOPERM after a successful AUTH; a wrong password answers NOAUTH.
            $ok = $exit === 0 && ($expected !== null ? ($lines[0] ?? null) === $expected : str_starts_with($lines[0] ?? '', 'PONG') || str_starts_with($lines[0] ?? '', 'NOPERM'));
            [$step, $code] = $ok ? [null, null] : ['Check the owner login', 'database.owner_login_failed'];
        }
        if ($code === null) {
            return [$data, ['backend' => 'active']];
        }

        return [[...$data, 'status' => 'failed', 'error_code' => $code, 'failure' => ['failed_step' => $step, 'stderr_recorded' => true]], ['error_code' => $code]];
    }

    private function server(Operation $operation): DatabaseServer
    {
        return DatabaseServer::query()->findOrFail($operation->target_id);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            [$data, $result] = $this->outcome($operation, $data);
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal) {
                $connection = DatabaseConnection::query()->find(ValidatedData::integer($operation->input['connection_id']));
                if ($connection !== null && ($data['status'] ?? null) === 'succeeded') {
                    if ($connection->owner_instance_id !== null) {
                        app(AttachDatabaseConnectionAction::class)->project(Instance::query()->findOrFail($connection->owner_instance_id), $connection, 'DB', replace: true);
                    }
                    app(RecordEventBroadcaster::class)->broadcast(RecordEventType::DatabaseCreated, $connection->id, DatabaseConnectionData::fromModel($connection)->toArray());
                } elseif ($connection !== null && ($data['status'] ?? null) === 'failed' && ($data['error_code'] ?? null) === 'database.index_invalid' && ! $connection->targets()->exists()) {
                    $connection->users()->delete();
                    $connection->delete();
                    app(RecordEventBroadcaster::class)->broadcast(RecordEventType::DatabaseDeleted, $connection->id, ['id' => $connection->id, 'slug' => $connection->slug]);
                }
            }

            return $projected;
        });
    }
}
