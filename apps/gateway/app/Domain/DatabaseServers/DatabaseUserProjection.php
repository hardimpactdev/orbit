<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseUser;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Safe admitted identity and record projection; the Node owns all SQL execution. */
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

        $node = Node::query()->findOrFail($operation->node_id);
        $copy = ['vault' => 'orbit-control-inputs', 'key' => 'database-user-create-intent-'.$intent->id];
        if (! $operation->status->terminal()) {
            $copy = app(SwampCommandStep::class)->executionCopy($copy['vault'], $copy['key'],
                json_encode(array_intersect_key($input, array_flip(['password', 'payload'])), JSON_THROW_ON_ERROR));
        }

        return ['intent' => ['driver' => $input['driver'], 'container' => $container,
            'database' => $input['database'], 'owner_username' => $input['owner_username'], 'read_only' => $input['read_only'],
            'username' => $username, 'replication' => $input['replication'], 'known_user' => $input['known_user'],
            'ssh' => ['host' => $node->wireguard_ip, 'user' => $node->user,
                'host_keys' => is_string($node->ssh_host_key_type) && is_string($node->ssh_host_key)
                    ? [$node->ssh_host_key_type.' '.$node->ssh_host_key] : []]], 'execution_secrets' => ['user_create' => $copy]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $intent = $this->intent($operation);
        $user = $intent->payload['projection']['upsert_user'] ?? null;
        if ($user === null) {
            $this->invalid();
        }
        $raw = ValidatedData::object($data['result'] ?? []);
        $result = ($raw['backend'] ?? null) === 'active' ? ['backend' => 'active'] : [];
        if (is_string($raw['error_code'] ?? null) && preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $raw['error_code']) === 1) {
            $result['error_code'] = $raw['error_code'];
        }
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
