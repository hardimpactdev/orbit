<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\DatabaseConnections\DatabaseResultRedactor;
use App\Infrastructure\DatabaseConnections\SqliteNodeDatabaseInspector;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\DatabaseConnection;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Database\Eloquent\Collection;

beforeEach(function (): void {
    $this->node = new Node(['id' => 7, 'user' => 'orbit', 'status' => 'active', 'wireguard_ip' => '10.44.0.7']);
    $this->node->id = 7;
    $this->node->setRelation('roles', new Collection([new NodeRole(['role' => 'app-dev'])]));
    $this->connection = new DatabaseConnection(['slug' => 'local', 'driver' => 'sqlite', 'node_id' => 7, 'path' => '/home/app/shared/database.sqlite']);
    $this->connection->setRelation('node', $this->node);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/keys/known_hosts');
    $this->ssh = Mockery::mock(SshExecutor::class);
    $this->inspector = new SqliteNodeDatabaseInspector($this->ssh, $keys, $hosts, new DatabaseResultRedactor(new CommandActivityInputSanitizer));
});

it('uses the production owner identity and protected stdin without exposing SQL on argv', function (): void {
    $this->node->setRelation('roles', new Collection([new NodeRole(['role' => 'app-prod'])]));
    $owner = new Instance(['node_id' => 7, 'production_user' => 'orbit-app-7', 'production_home' => '/home/orbit-app-7']);
    $owner->setRelation('node', $this->node);
    $this->connection->setRelation('ownerInstance', $owner);

    $this->ssh->shouldReceive('execute')->once()->withArgs(function (SshConnection $ssh, RemoteCommand $command): bool {
        expect($ssh->user)->toBe('orbit')
            ->and($command->arguments)->toBe(['sudo', '-n', '-u', 'orbit-app-7', '-H', 'orbit', 'internal:database-local']);
        $payload = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
        expect($payload['sql'])->toBe('SELECT 1')->and($payload['path'])->toBe($this->connection->path)
            ->and($payload['token'])->toMatch('/\A[a-f0-9]{64}\z/')->and($payload['write'])->toBeFalse();

        return true;
    })->andReturn(new CommandResult(0, '{"columns":["n"],"rows":[{"n":1}],"row_count":1,"truncated":false}', '', 1, false));

    expect($this->inspector->query($this->connection, 'SELECT 1', false)->rows)->toBe([['n' => 1]]);
});

it('keeps development inspection as the Node user', function (): void {
    $this->ssh->shouldReceive('execute')->once()->withArgs(function (SshConnection $ssh, RemoteCommand $command): bool {
        expect($ssh->user)->toBe('orbit')->and($command->arguments)->toBe(['orbit', 'internal:database-local']);

        return true;
    })->andReturn(new CommandResult(0, '{"columns":[],"rows":[],"row_count":0,"truncated":false}', '', 1, false));

    expect($this->inspector->query($this->connection, 'SELECT 1', false)->rows)->toBe([]);
});

it('maps inaccessible files and denied execution without returning remote secrets', function (string $stdout, string $stderr, string $code): void {
    $this->ssh->shouldReceive('execute')->once()->andReturn(new CommandResult(1, $stdout, $stderr, 1, false));

    try {
        $this->inspector->query($this->connection, 'SELECT 1', false);
        test()->fail('Inspection unexpectedly succeeded.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->getMessage())->not->toContain('private-remote-value');
    }
})->with([
    'file permissions' => ['{"error":{"code":"database.sqlite_unreadable","message":"private-remote-value"}}', '', 'database.sqlite_unreadable'],
    'sudo password' => ['', 'sudo: a password is required private-remote-value', 'database.sqlite_unreadable'],
    'sudo policy' => ['', "Sorry, user orbit is not allowed to execute 'private-remote-value' as orbit-app-7 on node.", 'database.sqlite_unreadable'],
    'sudoers missing' => ['', 'orbit is not in the sudoers file. private-remote-value', 'database.sqlite_unreadable'],
    'binary permissions' => ['', 'Permission denied private-remote-value', 'database.sqlite_unreadable'],
    'ordinary SQL failure' => ['{"error":{"code":"database.query_failed"}}', 'private-remote-value', 'database.query_failed'],
]);
