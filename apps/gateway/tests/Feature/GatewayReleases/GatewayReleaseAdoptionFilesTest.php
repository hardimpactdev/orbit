<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\GatewayReleases\GatewayReleaseAdopter;
use App\Infrastructure\GatewayReleases\GatewayReleaseAdoptionFiles;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'user' => 'orbit', 'status' => LifecycleStatus::Active]);
    $this->node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->agents = new FakeAgentCommandRunner;
    $this->files = new GatewayReleaseAdoptionFiles($this->agents);
    $this->layout = new GatewayReleaseLayout('/home/orbit/orbit/apps/gateway');
});

it('sends layout and adoption identity through the native boundary', function (): void {
    $this->agents->answer('gateway.adopt-release-storage', ['storage' => 'moved', 'storage_method' => 'exchange', 'storage_gap_us' => 0])
        ->answer('gateway.switch-release-adoption', ['method' => 'exchange', 'gap_us' => 0, 'from' => '/home/orbit/orbit.pre-adopt-20261024T120000Z', 'to' => 'releases/0123456789ab'])
        ->answer('gateway.restore-release-adoption', ['changed' => true])
        ->answer('gateway.finish-release-adoption', ['path' => null]);

    expect($this->files->storage($this->layout))->toBe(['storage' => 'moved', 'storage_method' => 'exchange', 'storage_gap_us' => 0]);
    expect($this->files->switch($this->layout, '0123456789ab', '20261024T120000Z'))->toHaveKey('method', 'exchange');
    $this->files->restore($this->layout, '0123456789ab', '20261024T120000Z');
    expect($this->files->finish($this->layout, '20261024T120000Z'))->toBeNull();
    expect($this->agents->calls)->toBe([
        ['node' => 'gateway', 'command' => 'gateway.adopt-release-storage', 'input' => ['checkout' => $this->layout->applicationPath]],
        ['node' => 'gateway', 'command' => 'gateway.switch-release-adoption', 'input' => ['checkout' => $this->layout->applicationPath, 'release' => '0123456789ab', 'stamp' => '20261024T120000Z']],
        ['node' => 'gateway', 'command' => 'gateway.restore-release-adoption', 'input' => ['checkout' => $this->layout->applicationPath, 'release' => '0123456789ab', 'stamp' => '20261024T120000Z']],
        ['node' => 'gateway', 'command' => 'gateway.finish-release-adoption', 'input' => ['checkout' => $this->layout->applicationPath, 'stamp' => '20261024T120000Z']],
    ]);
});

it('keeps a native storage conflict and maps an exchange failure into its release phase', function (): void {
    $this->agents->refuse('gateway.adopt-release-storage', 'gateway.release_adopt_storage_conflict');
    try {
        $this->files->storage($this->layout);
        $this->fail('Expected a native refusal.');
    } catch (GatewayReleaseException $e) {
        expect($e->errorCode)->toBe('gateway.release_adopt_storage_conflict')->and($e->step)->toBe('adopt');
    }
    $this->agents->refuse('gateway.restore-release-adoption', 'gateway.release_adoption_failed', ['stage' => 'restore-exchange']);
    try {
        $this->files->restore($this->layout, '0123456789ab', '20261024T120000Z');
        $this->fail('Expected a native refusal.');
    } catch (GatewayReleaseException $e) {
        expect($e->errorCode)->toBe('gateway.release_switch_back_failed')->and($e->step)->toBe('switch_back');
    }
});

it('refuses malformed output rather than claiming a completed transition', function (): void {
    $this->agents->answer('gateway.adopt-release-storage', ['storage' => 'moved'])
        ->answer('gateway.switch-release-adoption', ['method' => 'rename', 'gap_us' => 1, 'from' => 'a', 'to' => 'b'])
        ->answer('gateway.restore-release-adoption', ['changed' => 'yes'])
        ->answer('gateway.finish-release-adoption', []);
    foreach ([
        fn () => $this->files->storage($this->layout),
        fn () => $this->files->switch($this->layout, '0123456789ab', '20261024T120000Z'),
        fn () => $this->files->restore($this->layout, '0123456789ab', '20261024T120000Z'),
        fn () => $this->files->finish($this->layout, '20261024T120000Z'),
    ] as $call) {
        expect($call)->toThrow(GatewayReleaseException::class, 'invalid release adoption result');
    }
});

arch('release adoption files use only the command boundary')->expect(GatewayReleaseAdoptionFiles::class)->not->toUse([
    ProcessRunner::class,
    SshExecutor::class,
    RemoteProgramRunner::class,
]);

it('sends checkout repository target and environment intent without reading Node files', function (): void {
    $sha = str_repeat('a', 40);
    $this->agents->answer('gateway.check-release-adoption', ['sha' => $sha])
        ->answer('gateway.adopt-release-repository', ['repository' => 'created'])
        ->answer('gateway.inspect-adoption-origin', ['sha' => $sha])
        ->answer('gateway.resolve-adoption-target', ['sha' => $sha])
        ->answer('gateway.adopt-release-environment', ['env' => 'created', 'env_link' => 'linked', 'app_version_removed' => true, 'env_backups' => ['/home/orbit/shared/env-backups/.env.bak-1']])
        ->answer('gateway.restore-adoption-environment', ['environment' => 'restored']);

    expect($this->files->checkout($this->layout))->toBe($sha);
    expect($this->files->repository($this->layout, $sha))->toBe('created');
    expect($this->files->phaseOne($this->layout, substr($sha, 0, 12)))->toBeTrue();
    expect($this->files->target($this->layout, substr($sha, 0, 7), '/home/orbit/adopt-source'))->toBe($sha);
    expect($this->files->environment($this->layout))->toBe(['env' => 'created', 'env_link' => 'linked', 'app_version_removed' => true, 'env_backups' => ['/home/orbit/shared/env-backups/.env.bak-1']]);
    expect($this->files->restoreEnvironment($this->layout))->toBe('restored');
    expect($this->agents->calls)->toBe([
        ['node' => 'gateway', 'command' => 'gateway.check-release-adoption', 'input' => ['checkout' => $this->layout->applicationPath]],
        ['node' => 'gateway', 'command' => 'gateway.adopt-release-repository', 'input' => ['checkout' => $this->layout->applicationPath, 'sha' => $sha]],
        ['node' => 'gateway', 'command' => 'gateway.inspect-adoption-origin', 'input' => ['checkout' => $this->layout->applicationPath]],
        ['node' => 'gateway', 'command' => 'gateway.resolve-adoption-target', 'input' => ['checkout' => $this->layout->applicationPath, 'target' => substr($sha, 0, 7), 'source' => '/home/orbit/adopt-source']],
        ['node' => 'gateway', 'command' => 'gateway.adopt-release-environment', 'input' => ['checkout' => $this->layout->applicationPath]],
        ['node' => 'gateway', 'command' => 'gateway.restore-adoption-environment', 'input' => ['checkout' => $this->layout->applicationPath]],
    ]);
});

it('does not resume adoption for a missing or different pre-adopt commit', function (?string $sha): void {
    $this->agents->answer('gateway.inspect-adoption-origin', ['sha' => $sha]);

    expect($this->files->phaseOne($this->layout, 'aaaaaaaaaaaa'))->toBeFalse();
})->with([null, str_repeat('b', 40)]);

it('retains the unresolved target for the authenticated release builder to fetch', function (): void {
    $this->agents->answer('gateway.resolve-adoption-target', ['sha' => 'abcdef0']);

    expect($this->files->target($this->layout, 'abcdef0', null))->toBe('abcdef0');
});

it('refuses malformed preparation results', function (string $command, array $result, string $method, array $arguments): void {
    $this->agents->answer($command, $result);

    expect(fn () => $this->files->{$method}($this->layout, ...$arguments))->toThrow(GatewayReleaseException::class, 'invalid release adoption result');
})->with([
    ['gateway.check-release-adoption', ['sha' => 'main'], 'checkout', []],
    ['gateway.adopt-release-repository', ['repository' => 'replaced'], 'repository', [str_repeat('a', 40)]],
    ['gateway.inspect-adoption-origin', [], 'phaseOne', ['aaaaaaaaaaaa']],
    ['gateway.inspect-adoption-origin', ['sha' => 1], 'phaseOne', ['aaaaaaaaaaaa']],
    ['gateway.resolve-adoption-target', ['sha' => str_repeat('b', 40)], 'target', ['aaaaaaa', null]],
    ['gateway.adopt-release-environment', ['env' => 'created', 'env_link' => 'linked', 'app_version_removed' => true, 'env_backups' => [1]], 'environment', []],
    ['gateway.restore-adoption-environment', ['environment' => 'discarded'], 'restoreEnvironment', []],
]);

it('keeps preparation refusal codes and gives the changed path to the operator', function (): void {
    $this->agents->refuse('gateway.check-release-adoption', 'gateway.release_adopt_local_changes', ['count' => 1, 'path' => 'apps/gateway/public/index.php']);
    try {
        $this->files->checkout($this->layout);
        $this->fail('Expected a checkout refusal.');
    } catch (GatewayReleaseException $exception) {
        expect($exception->errorCode)->toBe('gateway.release_adopt_local_changes')->and($exception->getMessage())->toContain('apps/gateway/public/index.php');
    }
});

arch('release adoption owns no local process or filesystem implementation')->expect(GatewayReleaseAdopter::class)->not->toUse([
    ProcessRunner::class,
    SshExecutor::class,
    RemoteProgramRunner::class,
    'file_get_contents', 'file_put_contents', 'is_dir', 'is_file', 'is_link', 'readlink', 'rename', 'unlink', 'mkdir', 'glob',
]);
