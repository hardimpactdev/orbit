<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Data\DatabaseConnections\CreateServerDatabaseData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\MysqlNames;
use App\Domain\DatabaseServers\PreparedDatabaseOperation;
use App\Domain\Instances\DatabaseClone\DatabaseCloneStep;
use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Operation;

/** Called in lifecycle admission's transaction. Returns Kit v2 child intent, without Node effects. */
final readonly class PrepareDatabaseChildAction
{
    public function __construct(private CreateServerDatabaseAction $databases, private DropServerDatabaseAction $drops, private RecordDatabaseOperationAction $records, private MysqlNames $names, private PrepareSqliteSeedAbandonAction $seedAbandon) {}

    /** @return array{key: string, kind: string, node_id: int, input: array<string, mixed>} */
    public function create(Instance $target, DatabaseServer $server, Operation $parent): array
    {
        $server = $this->databases->activeServer($server->slug);
        $prepared = $this->databases->prepare(new CreateServerDatabaseData(
            slug: $this->slug($target), server: $server->slug, instanceId: $target->id,
        ), null);

        $owned = $this->owned($prepared, $target);

        return new PreparedDatabaseOperation($owned->kind, $owned->targetType, $owned->targetId, $owned->nodeId,
            [...$owned->input, 'server_id' => $server->id])->child($parent);
    }

    /** @return array{key: string, kind: string, node_id: int, input: array<string, mixed>} */
    public function clone(Instance $target, Instance $source, Operation $parent): array
    {
        if ($source->project_id !== $target->project_id) {
            throw new ResourceOperationException('instance.database_clone_unsupported', 'The source must belong to the same Project.', 422);
        }
        $sourceConnection = DatabaseConnectionTarget::query()->with('databaseConnection.server')
            ->where('instance_id', $source->id)->where('prefix', 'DB')->first()?->databaseConnection;
        if ($sourceConnection === null) {
            throw new ResourceOperationException('instance.database_clone_unsupported', 'The source has no default database attachment.', 422);
        }
        $children = [];
        if ($sourceConnection->driver === DatabaseDriver::Mysql && $sourceConnection->server !== null) {
            $server = $sourceConnection->server;
            $connection = $this->databases->reserve($server, $this->slug($target), $this->names->database($this->slug($target)), $target, null, ['clone_step' => 'recorded']);
            if ($connection->id === $sourceConnection->id || $connection->database === $sourceConnection->database) {
                throw new ResourceOperationException('instance.database_clone_unsupported', 'The source and target must be different databases.', 422);
            }
            $names = [$connection->database, $connection->test_database];

            return $this->records->prepare('database.mysql_clone', 'instance', $target->id, $server->node_id,
                ['owner_instance_id' => $target->id, 'source_instance_id' => $source->id, 'source_node_id' => $source->node_id,
                    'connection_id' => $connection->id, 'source_connection_id' => $sourceConnection->id, 'server_id' => $server->id, 'driver' => 'mysql'],
                ['database-apply' => ['name' => 'database.create', 'input' => [
                    'repair' => false, 'driver' => 'mysql', 'username' => $connection->username, 'databases' => $names,
                    'test_prefix' => $connection->test_database, 'locale' => null, 'host' => $server->node->wireguard_ip, 'port' => $server->port,
                    'container' => app(DockerProcessRenderer::class)->containerName($server->process ?? throw new ResourceOperationException('database.server_required', 'The server Process is missing.', 409)),
                    'index' => null, 'patterns' => [], 'allow' => [], 'source_database' => $sourceConnection->database,
                    'password' => $server->root_password, 'payload' => ['password' => $connection->password, 'salt' => base64_encode(random_bytes(16))],
                ], 'protected' => ['password', 'payload']]],
                ['created_connection' => $connection->id, 'attach_instance' => $target->id, 'clone' => true])->child($parent);
        } elseif ($sourceConnection->driver === DatabaseDriver::Sqlite) {
            $path = $sourceConnection->path;
            $base = rtrim($source->checkout_path, '/').'/';
            if ($path === null || ! str_starts_with($path, $base) || preg_match('#(?:^|/)\.{1,2}(?:/|$)|//#', $path)) {
                throw new ResourceOperationException('instance.database_clone_unsupported', 'The SQLite source must be inside its checkout.', 422);
            }
            $targetPath = rtrim($target->checkout_path, '/').'/'.substr($path, strlen($base));
            $connection = DatabaseConnection::query()->where('slug', $this->slug($target))->first();
            if ($connection !== null && ($connection->owner_instance_id !== $target->id || $connection->driver !== DatabaseDriver::Sqlite
                || $connection->path !== $targetPath || $connection->node_id !== $target->node_id || $connection->clone_step === DatabaseCloneStep::Complete)) {
                throw new ResourceOperationException('database.slug_conflict', 'The SQLite target belongs to another database intent.', 409);
            }
            $connection ??= DatabaseConnection::query()->create(['slug' => $this->slug($target), 'driver' => 'sqlite',
                'owner_instance_id' => $target->id, 'node_id' => $target->node_id, 'path' => $targetPath, 'test_database' => ':memory:', 'clone_step' => 'recorded']);
            $nodeId = $target->node_id;
            if ($source->node_id === $target->node_id) {
                $commands = ['database-apply' => ['name' => 'instance.copy-sqlite', 'input' => ['operation' => 'local',
                    'checkout' => $source->checkout_path, 'source' => $path, 'target_checkout' => $target->checkout_path, 'target' => $targetPath]]];
            } else {
                $directory = '/tmp/orbit-sqlite-clone-'.bin2hex(random_bytes(16));
                $incoming = dirname($targetPath).'/.orbit-sqlite-'.bin2hex(random_bytes(16));
                $export = $this->records->prepare('database.sqlite_export', 'instance', $target->id, $source->node_id,
                    ['owner_instance_id' => $target->id, 'source_instance_id' => $source->id, 'driver' => 'sqlite'],
                    ['database-apply' => ['name' => 'instance.copy-sqlite', 'input' => ['operation' => 'export', 'checkout' => $source->checkout_path, 'source' => $path, 'directory' => $directory]]]);
                $cleanup = $this->records->prepare('database.sqlite_cleanup', 'instance', $target->id, $source->node_id,
                    ['owner_instance_id' => $target->id, 'source_instance_id' => $source->id, 'driver' => 'sqlite'],
                    ['database-apply' => ['name' => 'instance.copy-sqlite', 'input' => ['operation' => 'cleanup', 'directory' => $directory], 'backend' => 'absent']]);
                $children = [[...$export->child(), 'phase' => 'before'], [...$cleanup->child(), 'phase' => 'after']];
                $commands = [
                    'prepare-target' => ['name' => 'instance.copy-sqlite', 'input' => ['operation' => 'prepare', 'target_checkout' => $target->checkout_path, 'target' => $targetPath]],
                    'transfer-snapshot' => ['transport' => 'database_sqlite', 'name' => 'database.receive-snapshot', 'export_intent_id' => $export->input['intent_id'],
                        'input' => ['source_instance_id' => $source->id, 'target_instance_id' => $target->id, 'source_path' => $directory.'/snapshot.sqlite', 'target_path' => $incoming, 'bytes' => 0, 'sha256' => '']],
                    'database-apply' => ['name' => 'instance.copy-sqlite', 'export_intent_id' => $export->input['intent_id'],
                        'input' => ['operation' => 'install', 'target_checkout' => $target->checkout_path, 'target' => $targetPath, 'incoming' => $incoming, 'bytes' => 0, 'sha256' => '', 'mode' => 0]],
                ];
            }
        } else {
            throw new ResourceOperationException('instance.database_clone_unsupported', 'Default database copy supports managed MySQL and SQLite.', 422);
        }
        $prepared = $this->records->prepare('database.clone', 'instance', $target->id, $nodeId,
            ['owner_instance_id' => $target->id, 'source_instance_id' => $source->id, 'source_node_id' => $source->node_id,
                'connection_id' => $connection->id, 'driver' => $sourceConnection->driver->value,
                'variant' => $children === [] ? 'sqlite_local' : 'sqlite_remote'], $commands,
            ['created_connection' => $connection->id, 'attach_instance' => $target->id, 'clone' => true, 'children' => $children]);

        return $prepared->child($parent);
    }

    /** @return array{key: string, kind: string, node_id: int, input: array<string, mixed>} */
    public function destroyOwned(Instance $target, Operation $parent): array
    {
        $connections = DatabaseConnection::query()->with(['server', 'users'])->where('owner_instance_id', $target->id)->orderBy('id')->get();
        $managed = array_values($connections->filter(static fn (DatabaseConnection $connection): bool => $connection->server !== null)->all());
        $children = array_map(fn (PreparedDatabaseOperation $prepared): array => [...$this->owned($prepared, $target)->child(), 'phase' => 'before'], $this->drops->prepareMany($managed, $target->id));
        $commands = [];
        $localIds = [];
        foreach ($connections as $connection) {
            if ($connection->server !== null) {
                continue;
            }
            $localIds[] = $connection->id;
            if ($connection->driver === DatabaseDriver::Sqlite && $connection->path !== null) {
                $commands['sqlite-remove-'.$connection->id] = ['name' => 'instance.copy-sqlite',
                    'input' => ['operation' => 'remove', 'checkout' => $target->checkout_path, 'path' => $connection->path], 'backend' => 'absent'];
            }
        }
        $commands['database-apply'] = ['transport' => 'gateway', 'name' => 'database.owned-ready', 'input' => ['owner_instance_id' => $target->id], 'backend' => 'absent'];

        return $this->records->prepare('database.destroy_owned', 'instance', $target->id, $target->node_id,
            ['owner_instance_id' => $target->id, 'connection_ids' => $connections->modelKeys(), 'database_node_ids' => array_values(array_unique(array_column($children, 'node_id')))],
            $commands, ['delete_connections' => $localIds, 'children' => $children])->child($parent);
    }

    /** Production clone or development transfer. Placements are the parent's recorded, validated intent.
     * @return array{key: string, kind: string, node_id: int, input: array<string, mixed>}
     */
    public function sqliteSeed(SqliteSeedPlacement $source, SqliteSeedPlacement $target, string $sourcePath, Operation $parent): array
    {
        $transfer = $source->instanceId === $target->instanceId && $source->environment === 'development'
            && $target->environment === 'development' && $source->node->id !== $target->node->id;
        $safePath = static fn (string $path): bool => str_starts_with($path, '/') && ! preg_match('#[\x00\r\n]|(?:^|/)\.{1,2}(?:/|$)|//#', $path);
        $safeUser = static fn (string $user): bool => preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $user) === 1;
        if ($source->instanceId < 1 || $target->instanceId < 1 || ($source->instanceId === $target->instanceId && ! $transfer)
            || ! in_array($source->environment, ['development', 'production'], true) || ($target->environment !== 'production' && ! $transfer)
            || ($transfer && ($target->operationId === null || trim($target->operationId) === '' || strlen($target->operationId) > 200
                || ($source->operationId !== null && $source->operationId !== $target->operationId)))
            || ! $safePath($source->basePath) || ! $safePath($target->basePath) || ! $safePath($sourcePath)
            || ! str_starts_with($sourcePath, $source->basePath.'/') || ! $safeUser($source->executionUser) || ! $safeUser($target->executionUser)
            || ($target->environment === 'production' && $target->basePath !== '/home/'.$target->executionUser)
            || filter_var($source->node->wireguard_ip, FILTER_VALIDATE_IP) === false || filter_var($target->node->wireguard_ip, FILTER_VALIDATE_IP) === false) {
            throw new ResourceOperationException('sqlite.seed_preflight_failed', 'The recorded SQLite seed source or target is unavailable.', 409);
        }
        $identity = hash('sha256', implode("\0", ['sqlite-seed-v1', (string) $source->instanceId, $source->environment, $source->basePath,
            $source->executionUser, (string) $target->instanceId, $target->basePath, $target->executionUser, $sourcePath,
            ...($target->environment === 'development' ? [(string) $source->node->id, (string) $target->node->id, $target->operationId ?? ''] : [])]));
        $root = '/var/lib/orbit/app-instance-sqlite-seeds';
        $sourceState = $root.'/source-'.$source->instanceId.'-target-'.$target->instanceId.($transfer ? '-'.$identity : '');
        $targetState = $root.'/target-'.$target->instanceId.($transfer ? '-'.$identity : '');
        $snapshot = '/tmp/orbit-sqlite-'.$identity.'.sqlite';
        $relative = $transfer ? substr($sourcePath, strlen($source->basePath) + 1) : 'database.sqlite';
        $sourceInput = ['base' => $source->basePath, 'source_path' => $sourcePath, 'source_user' => $source->executionUser,
            'transport_user' => $source->node->user, 'operation' => $identity, 'state_dir' => $sourceState, 'snapshot_path' => $snapshot];
        $targetInput = ['base' => $target->basePath, 'target_user' => $target->executionUser, 'transport_user' => $target->node->user,
            'operation' => $identity, 'state_dir' => $targetState, 'environment' => $target->environment, 'relative_path' => $relative,
            'expected_size' => 0, 'expected_digest' => ''];
        $export = $this->records->prepare('database.sqlite_export', 'instance', $target->instanceId, $source->node->id,
            ['owner_instance_id' => $target->instanceId, 'driver' => 'seed'],
            ['database-apply' => ['name' => 'instance.prepare-sqlite-seed', 'input' => [...$sourceInput, 'mode' => 'prepare']]]);
        $cleanup = $this->records->prepare('database.sqlite_cleanup', 'instance', $target->instanceId, $source->node->id,
            ['owner_instance_id' => $target->instanceId, 'driver' => 'seed'],
            ['database-apply' => ['name' => 'instance.prepare-sqlite-seed', 'input' => [...$sourceInput, 'mode' => 'cleanup'], 'backend' => 'absent']]);
        $commands = [
            'prepare-target' => ['name' => 'instance.install-sqlite-seed', 'export_intent_id' => $export->input['intent_id'], 'input' => [...$targetInput, 'mode' => 'prepare']],
            'transfer-snapshot' => ['transport' => 'database_sqlite', 'name' => 'database.receive-snapshot', 'export_intent_id' => $export->input['intent_id'], 'incoming_step' => 'prepare-target',
                'input' => ['source_instance_id' => $source->instanceId, 'target_instance_id' => $target->instanceId, 'source_node_id' => $source->node->id,
                    'source_base' => $source->basePath, 'source_user' => $source->executionUser, 'source_environment' => $source->environment,
                    'target_base' => $target->basePath, 'target_user' => $target->executionUser, 'target_environment' => $target->environment,
                    'source_path' => $snapshot, 'target_path' => '', 'bytes' => 0, 'sha256' => '']],
            'database-apply' => ['name' => 'instance.install-sqlite-seed', 'export_intent_id' => $export->input['intent_id'], 'input' => [...$targetInput, 'mode' => 'install']],
        ];

        return $this->records->prepare('database.sqlite_seed', 'instance', $target->instanceId, $target->node->id,
            ['owner_instance_id' => $target->instanceId, 'source_instance_id' => $source->instanceId, 'source_node_id' => $source->node->id, 'driver' => 'seed'], $commands,
            ['children' => [[...$export->child(), 'phase' => 'before'], [...$cleanup->child(), 'phase' => 'after']],
                ...($transfer ? ['seed_attempt' => ['id' => $target->operationId, 'operation' => $identity, 'source_cleanup_intent_id' => $cleanup->input['intent_id']]] : [])])->child($parent);
    }

    /** @return array{key: string, kind: string, node_id: int, input: array<string, mixed>} */
    public function sqliteSeedAbandon(Instance $target, int $seedIntentId, Operation $parent): array
    {
        return $this->seedAbandon->execute($target, $seedIntentId, $parent);
    }

    private function owned(PreparedDatabaseOperation $prepared, Instance $target): PreparedDatabaseOperation
    {
        // Bind before parent admission commits. Lifecycle database intent belongs to its owning Instance.
        DatabaseExecutionIntent::recorded($prepared->input['intent_id'])
            ->update(['target_type' => 'instance', 'target_id' => $target->id]);

        return new PreparedDatabaseOperation($prepared->kind, 'instance', $target->id, $prepared->nodeId,
            [...$prepared->input, 'owner_instance_id' => $target->id]);
    }

    private function slug(Instance $target): string
    {
        $slug = $target->project->slug.'-'.$target->name;

        return strlen($slug) <= 63 ? $slug : rtrim(substr($slug, 0, 54), '-').'-'.substr(sha1($slug), 0, 8);
    }
}
