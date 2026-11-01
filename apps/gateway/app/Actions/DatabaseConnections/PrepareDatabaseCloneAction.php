<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\MysqlNames;
use App\Domain\DatabaseServers\PreparedDatabaseOperation;
use App\Domain\Instances\DatabaseClone\DatabaseCloneStep;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\Node;

/** Records clone intent in the caller's admission transaction. No live database probes. */
final readonly class PrepareDatabaseCloneAction
{
    public function __construct(private CreateServerDatabaseAction $databases, private RecordDatabaseOperationAction $records, private MysqlNames $names, private DockerProcessRenderer $containers) {}

    public function prepare(Instance $target, Instance $source): PreparedDatabaseOperation
    {
        if ($source->project_id !== $target->project_id || $source->id === $target->id) {
            throw new ResourceOperationException('instance.database_clone_unsupported', 'The source must be another Instance in the same Project.', 422);
        }
        $sourceConnection = DatabaseConnectionTarget::query()->with('databaseConnection.server')
            ->where('instance_id', $source->id)->where('prefix', 'DB')->first()?->databaseConnection;
        if ($sourceConnection === null) {
            throw new ResourceOperationException('instance.database_clone_unsupported', 'The source has no default database attachment.', 422);
        }
        $slug = $target->project->slug.'-'.$target->name;
        $slug = strlen($slug) <= 63 ? $slug : rtrim(substr($slug, 0, 54), '-').'-'.substr(sha1($slug), 0, 8);
        if ($sourceConnection->driver === DatabaseDriver::Mysql && $sourceConnection->server !== null) {
            $server = $this->databases->activeServer($sourceConnection->server->slug);
            $connection = $this->databases->reserve($server, $slug, $this->names->database($slug), $target, null, ['clone_step' => 'recorded']);
            if ($connection->id === $sourceConnection->id || $connection->database === $sourceConnection->database) {
                throw new ResourceOperationException('instance.database_clone_unsupported', 'The source and target must be different databases.', 422);
            }
            $nodeId = $server->node_id;
            $input = ['variant' => 'mysql', 'node_id' => $nodeId, 'source_node_id' => $nodeId,
                'mysql' => ['repair' => false, 'driver' => 'mysql', 'username' => $connection->username,
                    'databases' => array_values(array_filter([$connection->database, $connection->test_database], static fn (?string $name): bool => $name !== null)),
                    'test_prefix' => $connection->test_database, 'locale' => null, 'host' => $server->node->wireguard_ip, 'port' => $server->port,
                    'container' => $this->containers->containerName($server->process ?? throw new ResourceOperationException('database.server_required', 'The server Process is missing.', 409)),
                    'index' => null, 'patterns' => [], 'allow' => [], 'source_database' => $sourceConnection->database],
                'password' => $server->root_password, 'payload' => ['password' => $connection->password, 'salt' => base64_encode(random_bytes(16))]];
        } elseif ($sourceConnection->driver === DatabaseDriver::Sqlite) {
            $path = $sourceConnection->path;
            $base = rtrim($source->checkout_path, '/');
            if ($path === null || ! str_starts_with($path, $base.'/') || preg_match('#[\x00\r\n]|(?:^|/)\.{1,2}(?:/|$)|//#', $path)) {
                throw new ResourceOperationException('instance.database_clone_unsupported', 'The SQLite source must be inside its checkout.', 422);
            }
            $targetCheckout = rtrim($target->checkout_path, '/');
            $targetPath = $targetCheckout.substr($path, strlen($base));
            $connection = DatabaseConnection::query()->where('slug', $slug)->first();
            if ($connection !== null && ($connection->owner_instance_id !== $target->id || $connection->driver !== DatabaseDriver::Sqlite
                || $connection->path !== $targetPath || $connection->node_id !== $target->node_id || $connection->clone_step === DatabaseCloneStep::Complete)) {
                throw new ResourceOperationException('database.slug_conflict', 'The SQLite target belongs to another database intent.', 409);
            }
            $connection ??= DatabaseConnection::query()->create(['slug' => $slug, 'driver' => 'sqlite',
                'owner_instance_id' => $target->id, 'node_id' => $target->node_id, 'path' => $targetPath, 'test_database' => ':memory:', 'clone_step' => 'recorded']);
            $nodeId = $target->node_id;
            $input = ['variant' => $source->node_id === $nodeId ? 'sqlite_local' : 'sqlite_remote',
                'node_id' => $nodeId, 'source_node_id' => $source->node_id,
                'source' => ['variant' => 'clone', 'node_id' => $source->node_id,
                    'transport_user' => $source->node->user, 'checkout' => $base, 'source' => $path, 'directory' => '/tmp/orbit-sqlite-clone-'.bin2hex(random_bytes(16))],
                'target' => ['node_id' => $nodeId, 'transport_user' => $target->node->user,
                    'checkout' => $targetCheckout, 'target' => $targetPath, 'incoming' => dirname($targetPath).'/.orbit-sqlite-'.bin2hex(random_bytes(16))]];
        } else {
            throw new ResourceOperationException('instance.database_clone_unsupported', 'Default database copy supports managed MySQL and SQLite.', 422);
        }

        $input['source_ssh'] = $this->ssh(Node::query()->findOrFail($input['source_node_id']));
        $input['target_ssh'] = $this->ssh(Node::query()->findOrFail($nodeId));

        return $this->records->prepare('database.clone', 'instance', $target->id, $nodeId,
            ['owner_instance_id' => $target->id, 'source_instance_id' => $source->id, 'source_node_id' => $input['source_node_id'],
                'server_id' => $sourceConnection->database_server_id,
                'connection_id' => $connection->id, 'source_connection_id' => $sourceConnection->id,
                'driver' => $sourceConnection->driver->value, 'variant' => $input['variant'],
                'participant_node_ids' => array_values(array_unique([$nodeId, $input['source_node_id']])),
            ],
            ['database-apply' => ['name' => 'database.clone', 'input' => $input]],
            ['created_connection' => $connection->id, 'attach_instance' => $target->id, 'clone' => true]);
    }

    /** @return array{host:string,user:string,host_keys:list<string>} */
    private function ssh(Node $node): array
    {
        if (! is_string($node->wireguard_ip) || filter_var($node->wireguard_ip, FILTER_VALIDATE_IP) === false) {
            throw new ResourceOperationException('database.clone_intent_invalid', 'The admitted clone Node has no address.', 422);
        }

        return ['host' => $node->wireguard_ip, 'user' => $node->user,
            'host_keys' => is_string($node->ssh_host_key_type) && is_string($node->ssh_host_key)
                ? [$node->ssh_host_key_type.' '.$node->ssh_host_key] : []];
    }
}
