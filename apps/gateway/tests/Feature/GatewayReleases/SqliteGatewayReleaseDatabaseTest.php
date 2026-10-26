<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\GatewayReleases\GatewayReleaseCommands;
use App\Infrastructure\GatewayReleases\SqliteGatewayReleaseDatabase;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $node = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'status' => LifecycleStatus::Active]);
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    config(['database.connections.release_snapshot' => ['driver' => 'sqlite', 'database' => '/home/orbit/.orbit/gateway.sqlite']]);
    $this->agents = new FakeAgentCommandRunner;
    $commands = new GatewayReleaseCommands($this->agents);
    $this->layout = new GatewayReleaseLayout('/home/orbit/orbit/apps/gateway');
    $this->database = new SqliteGatewayReleaseDatabase($commands, $this->layout, '/home/orbit/.orbit', '/home/orbit/.orbit/gateway-release-step.lock', connection: 'release_snapshot', keptSnapshots: 2, minimumFreeBytes: 1024);
});

it('sends the configured database and retention intent and returns the native size and snapshot', function (): void {
    $path = '/home/orbit/.orbit/backups/pre-0123456789ab-20261025T120000Z-0123456789abcdef.sqlite';
    $this->agents->answer('gateway.inspect-release-database', ['bytes' => 12345])
        ->answer('gateway.snapshot-release-database', ['path' => $path]);

    expect($this->database->snapshotBytes())->toBe(12345);
    expect($this->database->snapshot('0123456789ab'))->toBe($path);
    expect($this->agents->calls)->toBe([
        ['node' => 'gateway', 'command' => 'gateway.inspect-release-database', 'input' => ['database' => '/home/orbit/.orbit/gateway.sqlite']],
        ['node' => 'gateway', 'command' => 'gateway.snapshot-release-database', 'input' => [
            'database' => '/home/orbit/.orbit/gateway.sqlite', 'orbit_home' => '/home/orbit/.orbit', 'release' => '0123456789ab',
            'kept_snapshots' => 2, 'minimum_free_bytes' => 1024, 'step_lock' => '/home/orbit/.orbit/gateway-release-step.lock',
        ]],
    ]);
});

it('requests migrations for the prepared release and preserves a failed migration with its SHA', function (): void {
    $this->agents->answer('gateway.migrate-release', ['changed' => true]);
    $this->database->migrate('/home/orbit/releases/0123456789ab');
    expect($this->agents->calls)->toBe([
        ['node' => 'gateway', 'command' => 'gateway.migrate-release', 'input' => [
            'checkout' => $this->layout->applicationPath, 'release' => '0123456789ab', 'step_lock' => '/home/orbit/.orbit/gateway-release-step.lock',
        ]],
    ]);
    $sha = '0123456789ab'.str_repeat('c', 28);
    $this->agents->refuse('gateway.migrate-release', 'gateway.release_migrate_failed', ['sha' => $sha]);
    $failure = release_failure(fn () => $this->database->migrate('/home/orbit/releases/0123456789ab'));
    expect($failure->errorCode)->toBe('gateway.release_migrate_failed')
        ->and($failure->step)->toBe('migrate')->and($failure->sha)->toBe($sha);
});

it('maps native snapshot refusals without reporting a created snapshot', function (string $code, int $status): void {
    $this->agents->refuse('gateway.snapshot-release-database', $code);
    $failure = release_failure(fn () => $this->database->snapshot('0123456789ab'));
    expect($failure->errorCode)->toBe($code)->and($failure->step)->toBe('snapshot')->and($failure->status)->toBe($status);
})->with([
    ['gateway.release_disk_low', 409], ['gateway.release_snapshot_unavailable', 500],
    ['gateway.release_snapshot_failed', 500], ['gateway.release_in_progress', 409],
]);

it('refuses non-SQLite or nonpersistent database intent before sending a snapshot command', function (string $driver, string $path): void {
    config(['database.connections.release_snapshot' => ['driver' => $driver, 'database' => $path]]);
    expect(release_failure(fn () => $this->database->snapshot('0123456789ab'))->errorCode)->toBe('gateway.release_snapshot_unavailable');
    expect($this->agents->calls)->toBe([]);
})->with([['mysql', 'gateway'], ['sqlite', ':memory:'], ['sqlite', '']]);

it('has no file reservation for an in-memory configuration', function (): void {
    config(['database.connections.release_snapshot.database' => ':memory:']);
    expect($this->database->snapshotBytes())->toBe(0);
    expect($this->agents->calls)->toBe([]);
});

it('refuses malformed native results and migration paths outside the release layout', function (): void {
    $this->agents->answerMalformed('gateway.inspect-release-database', ['bytes' => -1])
        ->answerMalformed('gateway.snapshot-release-database', ['path' => '/outside/pre-0123456789ab.sqlite']);
    expect(fn () => $this->database->snapshotBytes())->toThrow(GatewayReleaseException::class, 'invalid release command result');
    expect(fn () => $this->database->snapshot('0123456789ab'))->toThrow(GatewayReleaseException::class, 'invalid release command result');
    $this->agents->calls = [];
    expect(release_failure(fn () => $this->database->migrate('/outside/releases/0123456789ab'))->errorCode)->toBe('gateway.release_layout_invalid');
    expect($this->agents->calls)->toBe([]);
});

arch('release database work uses the agent boundary')->expect(SqliteGatewayReleaseDatabase::class)
    ->not->toUse([ProcessRunner::class, 'PDO', 'filesize', 'disk_free_space', 'file_get_contents', 'glob']);
