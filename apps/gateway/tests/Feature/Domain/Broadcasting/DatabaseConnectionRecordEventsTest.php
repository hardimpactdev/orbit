<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\CreateDatabaseConnectionAction;
use App\Actions\DatabaseConnections\DestroyDatabaseConnectionAction;
use App\Actions\DatabaseConnections\UpdateDatabaseConnectionAction;
use App\Data\DatabaseConnections\AddDatabaseConnectionData;
use App\Data\DatabaseConnections\UpdateDatabaseConnectionData;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseConnectionUpdateProjection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseConnection;
use App\Models\Node;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeRoutePublicationSocket;

describe('DatabaseConnection record events', function (): void {
    it('broadcasts database.created when a new connection is registered', function (): void {
        Event::fake([RecordBroadcast::class]);

        $data = new AddDatabaseConnectionData(
            slug: 'app',
            driver: DatabaseDriver::Mysql,
            nodeId: null,
            host: 'db.example.test',
            port: null,
            database: 'app',
            path: null,
            username: 'app',
            password: 'secret',
        );

        $connection = new CreateDatabaseConnectionAction()->execute($data);

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::DatabaseCreated
                && $event->id === $connection->id
                && $event->data['slug'] === 'app',
        );
    });

    it('broadcasts database.updated only after the native update succeeds', function (): void {
        $node = Node::query()->create(['name' => 'update-worker', 'user' => 'orbit', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.8', 'wireguard_ip' => '10.44.0.8']);
        $this->markAsGateway($node);
        config(['swamp.run_token' => 'fake.token']);
        $socket = new FakeRoutePublicationSocket;
        $socket->reply = static fn (array $sent): array => [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-connection-update', 'runId' => 'e1589ed9-996b-4c9c-aa75-7749ef519210']]];
        app()->instance(WebSocketClient::class, $socket);
        $connection = DatabaseConnection::query()->create([
            'slug' => 'app',
            'driver' => DatabaseDriver::Mysql,
            'host' => 'db.example.test',
            'database' => 'app',
            'username' => 'app',
            'password' => 'secret',
        ]);

        Event::fake([RecordBroadcast::class]);

        $operation = app(UpdateDatabaseConnectionAction::class)->execute($connection, new UpdateDatabaseConnectionData(
            driverProvided: false,
            driver: null,
            nodeIdProvided: false,
            nodeId: null,
            hostProvided: true,
            host: 'db2.example.test',
            portProvided: false,
            port: null,
            databaseProvided: false,
            database: null,
            pathProvided: false,
            path: null,
            usernameProvided: false,
            username: null,
            passwordProvided: false,
            password: null,
        ));

        Event::assertNotDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::DatabaseUpdated);
        app(DatabaseConnectionUpdateProjection::class)->apply($operation, [
            'run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded',
            'started_at' => '2026-10-31T09:00:00.000Z', 'finished_at' => '2026-10-31T09:00:01.000Z', 'result' => ['backend' => 'active'],
        ]);

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::DatabaseUpdated
                && $event->id === $connection->id
                && $event->data['host'] === 'db2.example.test',
        );
    });

    it('broadcasts database.deleted with a minimal snapshot when a connection is removed', function (): void {
        $connection = DatabaseConnection::query()->create([
            'slug' => 'app',
            'driver' => DatabaseDriver::Mysql,
            'host' => 'db.example.test',
            'database' => 'app',
            'username' => 'app',
            'password' => 'secret',
        ]);

        Event::fake([RecordBroadcast::class]);

        app(DestroyDatabaseConnectionAction::class)->execute($connection);

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::DatabaseDeleted
                && $event->id === $connection->id
                && $event->data === ['id' => $connection->id, 'slug' => 'app'],
        );
    });
});
