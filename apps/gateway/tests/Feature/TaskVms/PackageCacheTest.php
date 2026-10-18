<?php

declare(strict_types=1);

use App\Actions\TaskVms\FillPackageCacheAction;
use App\Actions\TaskVms\ObservePackageCacheAction;
use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\ComposerPackageCache;
use App\Infrastructure\TaskVms\IncusHost;
use App\Infrastructure\TaskVms\TaskVmPackageCache;
use App\Jobs\TaskVms\FillTaskVmPackageCache;
use App\Models\Node;
use App\Models\PackageCacheEntry;
use App\Models\PackageCacheSnapshot;
use App\Models\Project;
use App\Models\TaskVm;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\TaskVmSettingsBuilder;
use Tests\Support\TestOrbitHome;

pest()->group('subprocess');

/** @param list<string> $arguments */
function package_cache_git(string $root, array $arguments): string
{
    return trim((new Process(['git', '-C', $root, '-c', 'user.name=Cache', '-c', 'user.email=cache@example.test', ...$arguments]))->mustRun()->getOutput());
}

function package_cache_commit(string $root): string
{
    package_cache_git($root, ['add', '.']);
    package_cache_git($root, ['commit', '--quiet', '--allow-empty', '-m', 'lock']);

    return package_cache_git($root, ['rev-parse', 'HEAD']);
}

it('queues only changed default-branch lock blobs and includes monorepo locks and deletions', function (): void {
    $root = TestOrbitHome::scratch('package-cache-git');
    mkdir($root, 0700, true);
    package_cache_git($root, ['init', '-b', 'main']);
    $project = Project::query()->create(['repository_url' => 'https://github.com/acme/cache.git', 'name' => 'Cache', 'slug' => 'cache', 'default_branch' => 'main']);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host(31), TaskVmSettingsBuilder::host(32)]]);
    Queue::fake([FillTaskVmPackageCache::class]);
    $observe = app(ObservePackageCacheAction::class);
    file_put_contents($root.'/composer.json', '{}');
    file_put_contents($root.'/composer.lock', '{"packages":[]}');
    mkdir($root.'/apps/api', 0700, true);
    file_put_contents($root.'/apps/api/composer.json', '{}');
    file_put_contents($root.'/apps/api/composer.lock', '{"packages":[]}');
    $first = package_cache_commit($root);

    $observe->execute($project, $root.'/.git', $first);
    PackageCacheSnapshot::query()->whereKey($project->id)->update(['fill_pending' => false]);
    file_put_contents($root.'/README', 'code only');
    $observe->execute($project, $root.'/.git', package_cache_commit($root));
    $observe->execute($project, $root.'/.git', package_cache_git($root, ['rev-parse', 'HEAD']));

    Queue::assertPushed(FillTaskVmPackageCache::class, 2);
    expect(array_keys(PackageCacheSnapshot::query()->findOrFail($project->id)->blobs))->toBe(['apps/api/composer.lock', 'composer.lock']);

    unlink($root.'/apps/api/composer.lock');
    $observe->execute($project, $root.'/.git', package_cache_commit($root));

    Queue::assertPushed(FillTaskVmPackageCache::class, 2);
    expect(array_keys(PackageCacheSnapshot::query()->findOrFail($project->id)->blobs))->toBe(['composer.lock']);
});

it('selects only locks with sibling manifests and removes selection when a manifest is deleted', function (): void {
    $root = TestOrbitHome::scratch('package-cache-manifests');
    mkdir($root.'/packages/php-sdk', 0700, true);
    mkdir($root.'/apps/api/tests/Fixtures', 0700, true);
    package_cache_git($root, ['init', '-b', 'main']);
    $project = Project::query()->create(['repository_url' => 'https://github.com/acme/cache.git', 'name' => 'Cache', 'slug' => 'cache']);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host(31)]]);
    Queue::fake([FillTaskVmPackageCache::class]);
    $lock = json_encode(['packages' => [['name' => 'symfony/finder', 'version' => 'v8.1.7', 'dist' => ['type' => 'zip', 'url' => 'https://api.github.com/repos/symfony/finder/zipball/'.str_repeat('a', 40)]]]], JSON_THROW_ON_ERROR);
    file_put_contents($root.'/packages/php-sdk/composer.json', '{}');
    file_put_contents($root.'/packages/php-sdk/composer.lock', $lock);
    copy(base_path('tests/Fixtures/Dependencies/Pnpm/pnpm-lock.yaml'), $root.'/apps/api/tests/Fixtures/pnpm-lock.yaml');
    $commit = package_cache_commit($root);
    app(ObservePackageCacheAction::class)->execute($project, $root.'/.git', $commit);
    $snapshot = PackageCacheSnapshot::query()->findOrFail($project->id);

    expect(array_keys($snapshot->fresh()->blobs))->toBe(['packages/php-sdk/composer.lock']);
    expect($snapshot->fresh()->artifacts)->toBe((new ComposerPackageCache)->read($lock));
    Queue::assertPushed(FillTaskVmPackageCache::class, 1);

    unlink($root.'/packages/php-sdk/composer.json');
    app(ObservePackageCacheAction::class)->execute($project, $root.'/.git', package_cache_commit($root));

    expect($snapshot->fresh()->artifacts)->toBe([]);
    expect($snapshot->fresh()->blobs)->toBe([]);
});

it('retries a pending fill at the same commit without reading the tree and keeps it pending', function (): void {
    $project = Project::query()->create(['repository_url' => 'https://github.com/acme/cache.git', 'name' => 'Cache', 'slug' => 'cache']);
    $snapshot = PackageCacheSnapshot::query()->create(['project_id' => $project->id, 'commit' => str_repeat('a', 40), 'blobs' => [], 'artifacts' => [], 'fill_pending' => true]);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host(31)]]);
    Queue::fake([FillTaskVmPackageCache::class]);

    app(ObservePackageCacheAction::class)->execute($project, '/nonexistent-mirror', $snapshot->commit);

    expect($snapshot->fresh()->fill_pending)->toBeTrue();
    Queue::assertPushed(FillTaskVmPackageCache::class, 1);
    $snapshot->update(['fill_pending' => false]);
    app(ObservePackageCacheAction::class)->execute($project, '/nonexistent-mirror', $snapshot->commit);
    Queue::assertPushed(FillTaskVmPackageCache::class, 1);
});

it('coalesces pending fills by host and permits a successor once processing starts', function (): void {
    config(['queue.connections.gateway.driver' => 'database']);
    FillTaskVmPackageCache::dispatch(31);
    FillTaskVmPackageCache::dispatch(31);
    FillTaskVmPackageCache::dispatch(32);

    expect(DB::table('jobs')->pluck('queue')->all())->toBe(['builds', 'builds']);
    // Laravel releases this uniqueness lock when a ShouldBeUniqueUntilProcessing job starts.
    (new UniqueLock(app(Repository::class)))->release(new FillTaskVmPackageCache(31));
    FillTaskVmPackageCache::dispatch(31);

    expect(DB::table('jobs')->count())->toBe(3);
});

it('lists current Project usage with verified hashes and removes usage when a lock removes a package', function (): void {
    $project = Project::query()->create(['repository_url' => 'https://github.com/acme/cache.git', 'name' => 'Shop', 'slug' => 'shop']);
    $node = Node::query()->create(['public_ssh_host' => '192.0.2.7', 'name' => 'cache-host', 'platform' => 'linux']);
    $artifact = (new ComposerPackageCache)->read(json_encode(['packages' => [['name' => 'acme/demo', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://api.github.com/repos/acme/demo/zipball/'.str_repeat('a', 40)]]]], JSON_THROW_ON_ERROR))[0];
    $snapshot = PackageCacheSnapshot::query()->create(['project_id' => $project->id, 'blobs' => [], 'artifacts' => [$artifact]]);
    PackageCacheEntry::query()->create(['project_id' => $project->id, 'host_node_id' => $node->id, 'manager' => 'composer', 'package' => 'acme/demo', 'version' => '1.0.0', 'key' => $artifact['key'], 'integrity' => 'sha256-'.str_repeat('a', 64)]);

    $expected = json_encode(['projects' => [['id' => $project->id, 'name' => 'Shop']], 'cached' => [['project_id' => $project->id, 'host_node_id' => $node->id, 'manager' => 'composer', 'integrity' => 'sha256-'.str_repeat('a', 64)]]], JSON_THROW_ON_ERROR);
    $this->artisan('task-vms:package-cache-usage acme/demo 1.0.0')->expectsOutput($expected)->assertSuccessful();
    $snapshot->update(['artifacts' => [[...$artifact, 'key' => 'composer/acme/demo/'.str_repeat('b', 40).'.zip']]]);
    $uncached = json_encode(['projects' => [['id' => $project->id, 'name' => 'Shop']], 'cached' => []], JSON_THROW_ON_ERROR);
    $this->artisan('task-vms:package-cache-usage acme/demo 1.0.0')->expectsOutput($uncached)->assertSuccessful();
    $snapshot->update(['artifacts' => []]);
    $this->artisan('task-vms:package-cache-usage acme/demo 1.0.0')->expectsOutput('{"projects":[],"cached":[]}')->assertSuccessful();
});

it('adds only missing verified archives and keeps Composer writes and cache misses private', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/package-cache/composer.py'), resource_path('task-vms/package-cache.py'), resource_path('task-vms/package-cache-seed.py')]);
    $process->setTimeout(60)->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    expect($process->getErrorOutput())->toContain('OK');
});

function package_cache_transport(SshExecutor $ssh): TaskVmPackageCache
{
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/keys/id';
        }

        public function publicKey(): string
        {
            return 'public';
        }
    };
    $known = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/keys/known';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };

    return new TaskVmPackageCache(new IncusHost($ssh, $keys, $known));
}

it('attaches a host-enforced read-only directory and refuses a writable existing device', function (): void {
    $project = Project::query()->create(['repository_url' => 'https://github.com/acme/cache.git', 'name' => 'Cache', 'slug' => 'cache']);
    $node = Node::query()->create(['public_ssh_host' => '192.0.2.7', 'name' => 'cache-host', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit']);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($node->id)]]);
    $vm = TaskVm::query()->create(['host_node_id' => $node->id, 'provider' => 'incus', 'name' => 'tvm-77', 'state' => 'ready', 'wireguard_ip' => '10.44.0.130', 'pi_token' => 'private']);
    $ssh = new class implements SshExecutor
    {
        public array $calls = [];

        public bool $writable = false;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->calls[] = $command->arguments;
            $devices = $this->writable ? ['package-cache' => ['type' => 'disk', 'path' => '/opt/orbit/package-cache', 'source' => '/var/lib/orbit/package-cache', 'readonly' => 'false']] : [];

            return new CommandResult(0, json_encode(['devices' => $devices], JSON_THROW_ON_ERROR), '', 1, false);
        }
    };
    $cache = package_cache_transport($ssh);

    $cache->prepare($vm, $project);

    expect($ssh->calls[2])->toBe(['sudo', '-n', 'incus', '--project', 'orbit-tasks', 'project', 'set', '--', 'orbit-tasks', 'restricted.devices.disk=allow', 'restricted.devices.disk.paths=/var/lib/orbit/package-cache']);
    expect($ssh->calls[3])->toBe(['sudo', '-n', 'incus', '--project', 'orbit-tasks', 'config', 'device', 'add', '--', 'tvm-77', 'package-cache', 'disk', 'source=/var/lib/orbit/package-cache', 'path=/opt/orbit/package-cache', 'readonly=true']);
    $ssh->writable = true;
    expect(fn () => $cache->prepare($vm, $project))->toThrow(TaskVmException::class, 'not the expected read-only');
});

it('publishes verified Project usage only after a successful fill and skips a superseded snapshot', function (): void {
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    Http::fake([
        'https://api.github.com/repos/acme/cache/installation' => Http::response(['id' => 9]),
        'https://api.github.com/repos/acme/uncovered/installation' => Http::response([], 404),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_cache_sentinel'], 201),
    ]);
    $uncovered = Project::query()->create(['repository_url' => 'https://github.com/acme/uncovered.git', 'name' => 'Uncovered', 'slug' => 'uncovered']);
    PackageCacheSnapshot::query()->create(['project_id' => $uncovered->id, 'blobs' => [], 'artifacts' => []]);
    $node = Node::query()->create(['public_ssh_host' => '192.0.2.7', 'name' => 'cache-host', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit']);
    $settings = TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($node->id)]]);
    $project = Project::query()->create(['repository_url' => 'https://github.com/acme/cache.git', 'name' => 'Shop', 'slug' => 'shop']);
    $artifact = (new ComposerPackageCache)->read('{"packages":[{"name":"acme/demo","version":"1.0.0","dist":{"type":"zip","url":"https://api.github.com/repos/acme/demo/zipball/'.str_repeat('a', 40).'"}}]}')[0];
    $snapshot = PackageCacheSnapshot::query()->create(['project_id' => $project->id, 'commit' => str_repeat('a', 40), 'blobs' => ['composer.lock' => str_repeat('a', 40)], 'artifacts' => [$artifact]]);
    $ssh = new class implements SshExecutor
    {
        public bool $fail = false;

        public ?Closure $duringFill = null;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $payload = json_decode(stream_get_contents($command->protectedInput?->stream() ?? throw new LogicException('Cache input must be protected.')), true, flags: JSON_THROW_ON_ERROR);
            expect($command->input)->toBeNull();
            expect(implode(' ', $command->arguments))->not->toContain('ghs_cache_sentinel');
            if ($payload['artifacts'] !== []) {
                expect($payload['github_token'])->toBe('ghs_cache_sentinel');
            }

            $hashes = [];
            foreach ($payload['artifacts'] as $artifact) {
                $hashes[$artifact['key']] = 'sha256-'.str_repeat('b', 64);
            }
            ($this->duringFill ?? static function (): void {})();

            return new CommandResult($this->fail ? 1 : 0, json_encode(['hashes' => $hashes, 'failures' => []], JSON_THROW_ON_ERROR), '', 1, false);
        }
    };
    $fill = new FillPackageCacheAction($settings, package_cache_transport($ssh));

    $fill->execute($node->id);

    expect(PackageCacheEntry::query()->sole()->integrity)->toBe('sha256-'.str_repeat('b', 64));
    $ssh->fail = true;
    expect(fn () => $fill->execute($node->id))->toThrow(TaskVmException::class);
    expect(PackageCacheEntry::query()->count())->toBe(1);
    $ssh->fail = false;
    $ssh->duringFill = fn () => $snapshot->update(['blobs' => [], 'artifacts' => [], 'fill_pending' => true]);
    $fill->execute($node->id);
    expect(PackageCacheEntry::query()->count())->toBe(1);
    expect($snapshot->fresh()->fill_pending)->toBeTrue();
    $ssh->duringFill = null;
    $fill->execute($node->id);
    expect(PackageCacheEntry::query()->count())->toBe(0);
});

it('publishes available cache artifacts without App coverage and rejects invalid returned hashes', function (): void {
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    Http::fake(['https://api.github.com/repos/acme/uncovered/installation' => Http::response([], 404)]);
    $node = Node::query()->create(['public_ssh_host' => '192.0.2.7', 'name' => 'cache-host', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit']);
    $settings = TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($node->id)]]);
    $project = Project::query()->create(['repository_url' => 'https://github.com/acme/uncovered.git', 'name' => 'Uncovered', 'slug' => 'uncovered']);
    PackageCacheSnapshot::query()->create(['project_id' => $project->id, 'blobs' => [], 'artifacts' => [
        ['key' => 'composer-miss', 'manager' => 'composer', 'package' => 'acme/demo', 'version' => '1.0.0', 'url' => 'https://api.github.com/repos/acme/demo/zipball/'.str_repeat('a', 40), 'integrity' => null],
        ['key' => 'npm-hit', 'manager' => 'npm', 'package' => 'demo', 'version' => '1.0.0', 'url' => 'https://registry.npmjs.org/demo/-/demo-1.0.0.tgz', 'integrity' => 'sha512-'.base64_encode(hash('sha512', 'archive', true))],
    ]]);
    $ssh = new class implements SshExecutor
    {
        public bool $invalid = false;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $payload = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
            expect($payload['github_token'])->toBeNull();
            $hashes = ['npm-hit' => 'sha256-'.str_repeat('b', 64)];
            if ($this->invalid) {
                $hashes['composer-miss'] = null;
            }

            return new CommandResult(0, json_encode(['hashes' => $hashes, 'failures' => ['composer-miss' => 'GitHubAuthenticationUnavailable']], JSON_THROW_ON_ERROR), '', 1, false);
        }
    };
    $fill = new FillPackageCacheAction($settings, package_cache_transport($ssh));

    expect(fn () => $fill->execute($node->id))->toThrow(TaskVmException::class, 'Some package cache artifacts failed');

    expect(PackageCacheEntry::query()->sole()->manager)->toBe('npm');
    $ssh->invalid = true;
    expect(fn () => $fill->execute($node->id))->toThrow(TaskVmException::class, 'no verified archive hash');
    expect(PackageCacheEntry::query()->sole()->manager)->toBe('npm');
});

it('records partial fills per Project and keeps them pending until every host succeeds', function (): void {
    Log::spy();
    $first = Node::query()->create(['public_ssh_host' => '192.0.2.7', 'name' => 'first', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit']);
    $second = Node::query()->create(['public_ssh_host' => '192.0.2.8', 'name' => 'second', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.8', 'user' => 'orbit']);
    $settings = TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($first->id), TaskVmSettingsBuilder::host($second->id)]]);
    $snapshots = [];
    foreach (['broken', 'healthy'] as $name) {
        $project = Project::query()->create(['repository_url' => 'https://github.com/acme/'.$name.'.git', 'name' => $name, 'slug' => $name]);
        $snapshots[$name] = PackageCacheSnapshot::query()->create(['project_id' => $project->id, 'blobs' => [], 'fill_pending' => true, 'artifacts' => [
            ['key' => $name, 'manager' => 'npm', 'package' => $name, 'version' => '1.0.0', 'url' => 'https://registry.npmjs.org/'.$name.'/-/'.$name.'-1.0.0.tgz', 'integrity' => 'sha256-'.base64_encode(hash('sha256', 'archive', true))],
        ]]);
    }
    $ssh = new class implements SshExecutor
    {
        public bool $failArtifact = true;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            return new CommandResult(0, json_encode([
                'hashes' => $this->failArtifact ? ['healthy' => 'sha256-'.str_repeat('a', 64)] : ['healthy' => 'sha256-'.str_repeat('a', 64), 'broken' => 'sha256-'.str_repeat('b', 64)],
                'failures' => $this->failArtifact ? ['broken' => 'HTTPError'] : [],
            ], JSON_THROW_ON_ERROR), '', 1, false);
        }
    };
    $fill = new FillPackageCacheAction($settings, package_cache_transport($ssh));

    expect(fn () => $fill->execute($first->id))->toThrow(TaskVmException::class, 'Some package cache artifacts failed');

    Log::shouldHaveReceived('warning')->once()->with('Package cache artifact failed.', ['host_node_id' => $first->id, 'key' => 'broken', 'failure' => 'HTTPError']);
    expect(PackageCacheEntry::query()->sole()->package)->toBe('healthy');
    expect($snapshots['healthy']->fresh()->fill_pending)->toBeTrue();
    expect($snapshots['broken']->fresh()->fill_pending)->toBeTrue();
    expect(fn () => $fill->execute($second->id))->toThrow(TaskVmException::class);
    expect($snapshots['healthy']->fresh()->fill_pending)->toBeFalse();
    expect($snapshots['broken']->fresh()->fill_pending)->toBeTrue();
    $ssh->failArtifact = false;
    $fill->execute($first->id);
    expect($snapshots['broken']->fresh()->fill_pending)->toBeTrue();
    $fill->execute($second->id);
    expect($snapshots['broken']->fresh()->fill_pending)->toBeFalse();
    expect(PackageCacheEntry::query()->where('project_id', $snapshots['broken']->project_id)->pluck('host_node_id')->all())->toEqualCanonicalizing([$first->id, $second->id]);
});

it('isolates conflicting archive checksums without blocking another Project', function (): void {
    $node = Node::query()->create(['public_ssh_host' => '192.0.2.7', 'name' => 'cache-host', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit']);
    $settings = TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($node->id)]]);
    foreach (['first', 'second', 'healthy'] as $name) {
        $project = Project::query()->create(['repository_url' => 'https://github.com/acme/'.$name.'.git', 'name' => $name, 'slug' => $name]);
        PackageCacheSnapshot::query()->create(['project_id' => $project->id, 'blobs' => [], 'fill_pending' => true, 'artifacts' => [
            ['key' => $name === 'healthy' ? 'healthy' : 'conflict', 'manager' => 'composer', 'package' => 'acme/'.$name, 'version' => '1.0.0', 'url' => 'https://api.github.com/repos/acme/demo/zipball/'.str_repeat('a', 40), 'integrity' => 'sha1-'.hash('sha1', $name)],
        ]]);
    }
    $ssh = new class implements SshExecutor
    {
        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $payload = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
            expect(array_column($payload['artifacts'], 'key'))->toBe(['healthy']);

            return new CommandResult(0, json_encode(['hashes' => ['healthy' => 'sha256-'.str_repeat('a', 64)], 'failures' => []], JSON_THROW_ON_ERROR), '', 1, false);
        }
    };

    expect(fn () => (new FillPackageCacheAction($settings, package_cache_transport($ssh)))->execute($node->id))->toThrow(TaskVmException::class);

    expect(PackageCacheEntry::query()->sole()->package)->toBe('acme/healthy');
    expect(PackageCacheSnapshot::query()->orderBy('project_id')->pluck('fill_pending')->all())->toBe([true, true, false]);
});

it('requires every host to verify a changed Composer checksum even when its archive key stays the same', function (): void {
    $root = TestOrbitHome::scratch('package-cache-checksum-change');
    mkdir($root, 0700, true);
    package_cache_git($root, ['init', '-b', 'main']);
    file_put_contents($root.'/composer.json', '{}');
    $lock = ['packages' => [['name' => 'acme/demo', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://api.github.com/repos/acme/demo/zipball/'.str_repeat('a', 40), 'shasum' => str_repeat('a', 40)]]]];
    file_put_contents($root.'/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
    $project = Project::query()->create(['repository_url' => 'https://github.com/acme/cache.git', 'name' => 'Cache', 'slug' => 'cache']);
    $first = Node::query()->create(['public_ssh_host' => '192.0.2.7', 'name' => 'first', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit']);
    $second = Node::query()->create(['public_ssh_host' => '192.0.2.8', 'name' => 'second', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.8', 'user' => 'orbit']);
    $settings = TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($first->id), TaskVmSettingsBuilder::host($second->id)]]);
    Queue::fake([FillTaskVmPackageCache::class]);
    $observe = app(ObservePackageCacheAction::class);
    $observe->execute($project, $root.'/.git', package_cache_commit($root));
    $snapshot = PackageCacheSnapshot::query()->findOrFail($project->id);
    $key = $snapshot->artifacts[0]['key'];
    $ssh = new class implements SshExecutor
    {
        public bool $fail = false;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $payload = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);

            return new CommandResult($this->fail ? 1 : 0, json_encode(['hashes' => [$payload['artifacts'][0]['key'] => 'sha256-'.str_repeat('b', 64)], 'failures' => []], JSON_THROW_ON_ERROR), '', 1, false);
        }
    };
    $fill = new FillPackageCacheAction($settings, package_cache_transport($ssh));
    $fill->execute($first->id);
    $fill->execute($second->id);
    expect($snapshot->fresh()->fill_pending)->toBeFalse();
    expect(PackageCacheEntry::query()->count())->toBe(2);
    $lock['packages'][0]['dist']['shasum'] = str_repeat('c', 40);
    file_put_contents($root.'/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));

    $observe->execute($project, $root.'/.git', package_cache_commit($root));

    expect($snapshot->fresh()->artifacts[0]['key'])->toBe($key);
    expect(PackageCacheEntry::query()->count())->toBe(0);
    $fill->execute($first->id);
    $ssh->fail = true;
    expect(fn () => $fill->execute($second->id))->toThrow(TaskVmException::class);
    expect($snapshot->fresh()->fill_pending)->toBeTrue();
    expect(PackageCacheEntry::query()->sole()->host_node_id)->toBe($first->id);
});
