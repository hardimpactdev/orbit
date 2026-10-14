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
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
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
    file_put_contents($root.'/composer.lock', '{"packages":[]}');
    mkdir($root.'/apps/api', 0700, true);
    file_put_contents($root.'/apps/api/composer.lock', '{"packages":[]}');
    $first = package_cache_commit($root);

    $observe->execute($project, $root.'/.git', $first);
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

    expect($process->getErrorOutput())->toContain('OK');
    expect($process->isSuccessful())->toBeTrue();
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
            $payload = json_decode($command->input ?? '', true, flags: JSON_THROW_ON_ERROR);
            $hashes = [];
            foreach ($payload['artifacts'] as $artifact) {
                $hashes[$artifact['key']] = 'sha256-'.str_repeat('b', 64);
            }
            ($this->duringFill ?? static function (): void {})();

            return new CommandResult($this->fail ? 1 : 0, json_encode($hashes, JSON_THROW_ON_ERROR), '', 1, false);
        }
    };
    $fill = new FillPackageCacheAction($settings, package_cache_transport($ssh));

    $fill->execute($node->id);

    expect(PackageCacheEntry::query()->sole()->integrity)->toBe('sha256-'.str_repeat('b', 64));
    $ssh->fail = true;
    expect(fn () => $fill->execute($node->id))->toThrow(TaskVmException::class);
    expect(PackageCacheEntry::query()->count())->toBe(1);
    $ssh->fail = false;
    $ssh->duringFill = fn () => $snapshot->update(['blobs' => [], 'artifacts' => []]);
    $fill->execute($node->id);
    expect(PackageCacheEntry::query()->count())->toBe(1);
    $ssh->duringFill = null;
    $fill->execute($node->id);
    expect(PackageCacheEntry::query()->count())->toBe(0);
});
