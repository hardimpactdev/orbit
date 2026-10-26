<?php

declare(strict_types=1);

use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Instances\Sqlite\SqliteSnapshotTransfer;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\RemoteInstanceSqliteCloner;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Tests\Support\FakeAgentCommandRunner;

function sqlite_cloner_node(string $name, string $address): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => $address,
        'wireguard_ip' => $address,
        'user' => (string) posix_getpwuid(posix_geteuid())['name'],
    ]);
}

function sqlite_cloner_instance(Project $project, Node $node, string $name, string $checkout): Instance
{
    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => $checkout,
    ]);
}

function sqlite_clone_intent(): array
{
    $project = Project::query()->create(['name' => 'Acme', 'slug' => 'acme', 'repository_url' => 'https://github.com/acme/site.git']);
    $node = sqlite_cloner_node('source', '10.44.0.11');

    return [$project, $node, sqlite_cloner_instance($project, $node, 'default', '/apps/default')];
}

it('requests one local copy on the shared Node with both checkout boundaries', function (): void {
    [$project, $node, $source] = sqlite_clone_intent();
    $target = sqlite_cloner_instance($project, $node, 'feature', '/apps/feature');
    $agents = new FakeAgentCommandRunner()->answer('instances.copy-sqlite', ['bytes' => 4096, 'sha256' => str_repeat('a', 64), 'mode' => 0640]);
    $transfer = Mockery::mock(SqliteSnapshotTransfer::class);
    $transfer->shouldNotReceive('transfer');

    new RemoteInstanceSqliteCloner($agents, $transfer)->copy($source, '/apps/default/database.sqlite', $target, '/apps/feature/database.sqlite');

    expect($agents->calls)->toBe([['node' => 'source', 'command' => 'instances.copy-sqlite', 'input' => [
        'operation' => 'local', 'checkout' => '/apps/default', 'source' => '/apps/default/database.sqlite',
        'target_checkout' => '/apps/feature', 'target' => '/apps/feature/database.sqlite',
    ]]]);
});

it('exports transfers installs and always requests source cleanup across Nodes', function (bool $transferFails): void {
    [$project, $node, $source] = sqlite_clone_intent();
    $target = sqlite_cloner_instance($project, sqlite_cloner_node('target', '10.44.0.12'), 'feature', '/apps/feature');
    $agents = new FakeAgentCommandRunner()->answer('instances.copy-sqlite', static fn (array $input): array => $input['operation'] === 'export'
        ? ['bytes' => 4096, 'sha256' => str_repeat('a', 64), 'mode' => 0640] : []);
    $transfer = Mockery::mock(SqliteSnapshotTransfer::class);
    $transfer->shouldReceive('transfer')->once()->withArgs(function (SqliteSeedPlacement $from, string $fromPath, SqliteSeedPlacement $to, string $toPath, int $bytes, string $digest) use ($source, $target, $agents): bool {
        expect($from->instanceId)->toBe($source->id)
            ->and($to->instanceId)->toBe($target->id)
            ->and($fromPath)->toBe($agents->calls[0]['input']['directory'].'/snapshot.sqlite')
            ->and($toPath)->toStartWith('/apps/feature/.orbit-sqlite-')
            ->and($bytes)->toBe(4096)->and($digest)->toBe(str_repeat('a', 64));

        return true;
    })->andReturnUsing(static function () use ($transferFails): void {
        if ($transferFails) {
            throw new RuntimeException('Transfer unavailable.');
        }
    });
    $copy = fn () => new RemoteInstanceSqliteCloner($agents, $transfer)->copy($source, '/apps/default/database.sqlite', $target, '/apps/feature/database.sqlite');

    if ($transferFails) {
        expect($copy)->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('instance.database_clone_failed'));
    } else {
        $copy();
    }

    expect(array_column(array_column($agents->calls, 'input'), 'operation'))->toBe($transferFails ? ['export', 'prepare', 'cleanup'] : ['export', 'prepare', 'install', 'cleanup'])
        ->and(end($agents->calls)['input'])->toBe(['operation' => 'cleanup', 'directory' => $agents->calls[0]['input']['directory']]);
    if (! $transferFails) {
        expect($agents->calls[2]['node'])->toBe('target')
            ->and($agents->calls[2]['input'])->toMatchArray(['operation' => 'install', 'target_checkout' => '/apps/feature', 'target' => '/apps/feature/database.sqlite', 'bytes' => 4096, 'sha256' => str_repeat('a', 64), 'mode' => 0640]);
    }
})->with([false, true]);

it('maps native refusals and malformed reports to the public clone failure', function (bool $malformed): void {
    [$project, $node, $source] = sqlite_clone_intent();
    $target = sqlite_cloner_instance($project, $node, 'feature', '/apps/feature');
    $agents = new FakeAgentCommandRunner;
    $malformed ? $agents->answer('instances.copy-sqlite', ['bytes' => 'bad']) : $agents->refuse('instances.copy-sqlite', 'instances.sqlite_copy_refused');

    expect(fn () => new RemoteInstanceSqliteCloner($agents, Mockery::mock(SqliteSnapshotTransfer::class))->copy($source, '/apps/default/database.sqlite', $target, '/apps/feature/database.sqlite'))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('instance.database_clone_failed'));
})->with([false, true]);

it('requests removal within the owning checkout', function (): void {
    [, , $source] = sqlite_clone_intent();
    $agents = new FakeAgentCommandRunner()->answer('instances.copy-sqlite', []);

    new RemoteInstanceSqliteCloner($agents, Mockery::mock(SqliteSnapshotTransfer::class))->remove($source, '/apps/default/database.sqlite');

    expect($agents->calls[0]['input'])->toBe(['operation' => 'remove', 'checkout' => '/apps/default', 'path' => '/apps/default/database.sqlite']);
});
