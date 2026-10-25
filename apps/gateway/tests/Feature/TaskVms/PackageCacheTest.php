<?php

declare(strict_types=1);

use App\Actions\TaskVms\FillPackageCacheAction;
use App\Actions\TaskVms\ObservePackageCacheAction;
use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\AgentCommands\AgentBinaryInput;
use App\Infrastructure\AgentCommands\AgentBinaryOutput;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentGitHubToken;
use App\Infrastructure\TaskVms\ComposerPackageCache;
use App\Infrastructure\TaskVms\TaskVmPackageCache;
use App\Jobs\TaskVms\FillTaskVmPackageCache;
use App\Models\Node;
use App\Models\PackageCacheEntry;
use App\Models\PackageCacheSnapshot;
use App\Models\Project;
use App\Models\TaskVm;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeGuestAgentCommandRunner;
use Tests\Support\TaskVmSettingsBuilder;
use Tests\Support\TestOrbitHome;

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

it('coalesces queued fills by host until processing starts', function (): void {
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

abstract class CacheAgentFixture implements AgentCommandRunner
{
    public function install(Node $node): bool
    {
        throw new LogicException('No cache test should install an agent.');
    }
}

function package_cache_transport(AgentCommandRunner $agents): TaskVmPackageCache
{
    return new TaskVmPackageCache($agents, new FakeGuestAgentCommandRunner);
}

it('maps typed cache failures with bounded sanitized evidence', function (): void {
    $node = Node::query()->create(['public_ssh_host' => '192.0.2.7', 'name' => 'cache-host', 'platform' => 'linux']);
    $settings = TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($node->id)]]);
    $agents = (new FakeAgentCommandRunner)->refuse('package-cache.host', 'task_vm.package_cache_failed', [
        'operation' => 'fill', 'path' => TaskVmPackageCache::HostPath,
        'reason' => "old output\n".str_repeat("progress\n", 1000)."PASSWORD=cache-secret\nPermissionError: cache file\n",
    ]);

    expect(fn () => package_cache_transport($agents)->fill($settings->host($node->id), $node, []))->toThrow(function (TaskVmException $error): void {
        expect($error->errorCode)->toBe('task_vm.package_cache_failed')
            ->and($error->getMessage())->toContain('[fill]', '['.TaskVmPackageCache::HostPath.']', 'PermissionError')
            ->not->toContain('cache-secret', 'old output')
            ->and(mb_strlen($error->getMessage()))->toBeLessThanOrEqual(TaskVmException::MessageLimit);
    });
});

it('requests a fixed read-only mount before seeding the selected guest inventory', function (): void {
    $project = Project::query()->create(['repository_url' => 'https://github.com/acme/cache.git', 'name' => 'Cache', 'slug' => 'cache']);
    $node = Node::query()->create(['public_ssh_host' => '192.0.2.7', 'name' => 'cache-host', 'platform' => 'linux', 'architecture' => 'x86_64']);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($node->id)]]);
    $vm = TaskVm::query()->create(['host_node_id' => $node->id, 'provider' => 'incus', 'name' => 'tvm-77', 'state' => 'ready', 'wireguard_ip' => '10.44.0.130', 'pi_token' => 'private']);
    $artifacts = [['key' => 'selected']];
    PackageCacheSnapshot::query()->create(['project_id' => $project->id, 'blobs' => [], 'artifacts' => $artifacts]);
    $agents = (new FakeAgentCommandRunner)->answer('package-cache.host', [])->answer('incus.package-cache', ['ready' => true]);
    $guests = new FakeGuestAgentCommandRunner;
    $cache = new TaskVmPackageCache($agents, $guests);

    $cache->prepare($vm, $project);

    expect($agents->commands())->toBe(['package-cache.host', 'incus.package-cache'])
        ->and($agents->calls[0]['input'])->toBe(['operation' => 'initialize'])
        ->and($agents->calls[1]['input'])->toBe(['project' => 'orbit-tasks', 'name' => 'tvm-77'])
        ->and($guests->calls[0]['command'])->toBe('package-cache.seed')
        ->and($guests->calls[0]['guest']->name)->toBe('tvm-77')
        ->and($guests->calls[0]['input'])->toBe(['artifacts' => $artifacts]);
    $agents->refuse('incus.package-cache', 'task_vm.package_cache_mount_invalid');
    expect(fn () => $cache->prepare($vm, $project))->toThrow(function (TaskVmException $error): void {
        expect($error->errorCode)->toBe('task_vm.package_cache_mount_invalid');
    });
    expect($guests->calls)->toHaveCount(1);
});

it('stops before guest seeding when the mount does not confirm completion', function (array $result): void {
    $project = Project::query()->create(['repository_url' => 'https://github.com/acme/cache.git', 'name' => 'Cache', 'slug' => 'cache']);
    $node = Node::query()->create(['public_ssh_host' => '192.0.2.7', 'name' => 'cache-host', 'platform' => 'linux']);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($node->id)]]);
    $vm = TaskVm::query()->create(['host_node_id' => $node->id, 'provider' => 'incus', 'name' => 'tvm-77', 'state' => 'ready', 'wireguard_ip' => '10.44.0.130', 'pi_token' => 'private']);
    $agents = (new FakeAgentCommandRunner)->answer('package-cache.host', [])->answer('incus.package-cache', $result);
    $guests = new FakeGuestAgentCommandRunner;

    expect(fn () => (new TaskVmPackageCache($agents, $guests))->prepare($vm, $project))->toThrow(function (TaskVmException $error): void {
        expect($error->errorCode)->toBe('task_vm.package_cache_invalid_result');
    });
    expect($guests->calls)->toBe([]);
})->with(['missing' => [[]], 'false' => [['ready' => false]], 'mistyped' => [['ready' => 'true']]]);

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
    $agents = new class extends CacheAgentFixture
    {
        public bool $fail = false;

        public ?Closure $duringFill = null;

        public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null): array
        {
            $payload = $input;
            if ($payload['artifacts'] !== []) {
                expect($payload['github_token'])->toBeInstanceOf(AgentGitHubToken::class)
                    ->and($payload['github_token']->value)->toBe('ghs_cache_sentinel');
            }

            $hashes = [];
            foreach ($payload['artifacts'] as $artifact) {
                $hashes[$artifact['key']] = 'sha256-'.str_repeat('b', 64);
            }
            ($this->duringFill ?? static function (): void {})();

            if ($this->fail) {
                throw new AgentCommandRefused($command, 'task_vm.package_cache_failed', 'fill failed');
            }

            return ['hashes' => $hashes, 'failures' => []];
        }
    };
    $fill = new FillPackageCacheAction($settings, package_cache_transport($agents));

    $fill->execute($node->id);

    expect(PackageCacheEntry::query()->sole()->integrity)->toBe('sha256-'.str_repeat('b', 64));
    $agents->fail = true;
    expect(fn () => $fill->execute($node->id))->toThrow(TaskVmException::class);
    expect(PackageCacheEntry::query()->count())->toBe(1);
    $agents->fail = false;
    $agents->duringFill = fn () => $snapshot->update(['blobs' => [], 'artifacts' => [], 'fill_pending' => true]);
    $fill->execute($node->id);
    expect(PackageCacheEntry::query()->count())->toBe(1);
    expect($snapshot->fresh()->fill_pending)->toBeTrue();
    $agents->duringFill = null;
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
    $agents = new class extends CacheAgentFixture
    {
        public bool $invalid = false;

        public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null): array
        {
            $payload = $input;
            expect($payload['github_token'])->toBeNull();
            $hashes = ['npm-hit' => 'sha256-'.str_repeat('b', 64)];
            if ($this->invalid) {
                $hashes['composer-miss'] = null;
            }

            return ['hashes' => $hashes, 'failures' => ['composer-miss' => 'GitHub installation token unavailable']];
        }
    };
    $fill = new FillPackageCacheAction($settings, package_cache_transport($agents));

    expect(fn () => $fill->execute($node->id))->toThrow(TaskVmException::class, 'Some package cache artifacts failed');

    expect(PackageCacheEntry::query()->sole()->manager)->toBe('npm');
    $agents->invalid = true;
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
    $agents = new class extends CacheAgentFixture
    {
        public bool $failArtifact = true;

        public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null): array
        {
            return [
                'hashes' => $this->failArtifact ? ['healthy' => 'sha256-'.str_repeat('a', 64)] : ['healthy' => 'sha256-'.str_repeat('a', 64), 'broken' => 'sha256-'.str_repeat('b', 64)],
                'failures' => $this->failArtifact ? ['broken' => 'HTTP 429'] : [],
            ];
        }
    };
    $fill = new FillPackageCacheAction($settings, package_cache_transport($agents));

    expect(fn () => $fill->execute($first->id))->toThrow(TaskVmException::class, 'Some package cache artifacts failed');

    Log::shouldHaveReceived('warning')->once()->with('Package cache artifact failed.', ['host_node_id' => $first->id, 'key' => 'broken', 'failure' => 'HTTP 429']);
    expect(PackageCacheEntry::query()->sole()->package)->toBe('healthy');
    expect($snapshots['healthy']->fresh()->fill_pending)->toBeTrue();
    expect($snapshots['broken']->fresh()->fill_pending)->toBeTrue();
    expect(fn () => $fill->execute($second->id))->toThrow(TaskVmException::class);
    expect($snapshots['healthy']->fresh()->fill_pending)->toBeFalse();
    expect($snapshots['broken']->fresh()->fill_pending)->toBeTrue();
    $agents->failArtifact = false;
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
    $agents = new class extends CacheAgentFixture
    {
        public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null): array
        {
            $payload = $input;
            expect(array_column($payload['artifacts'], 'key'))->toBe(['healthy']);

            return ['hashes' => ['healthy' => 'sha256-'.str_repeat('a', 64)], 'failures' => []];
        }
    };

    expect(fn () => (new FillPackageCacheAction($settings, package_cache_transport($agents)))->execute($node->id))->toThrow(TaskVmException::class);

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
    $agents = new class extends CacheAgentFixture
    {
        public bool $fail = false;

        public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null): array
        {
            $payload = $input;

            if ($this->fail) {
                throw new AgentCommandRefused($command, 'task_vm.package_cache_failed', 'fill failed');
            }

            return ['hashes' => [$payload['artifacts'][0]['key'] => 'sha256-'.str_repeat('b', 64)], 'failures' => []];
        }
    };
    $fill = new FillPackageCacheAction($settings, package_cache_transport($agents));
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
    $agents->fail = true;
    expect(fn () => $fill->execute($second->id))->toThrow(TaskVmException::class);
    expect($snapshot->fresh()->fill_pending)->toBeTrue();
    expect(PackageCacheEntry::query()->sole()->host_node_id)->toBe($first->id);
});

it('accepts bounded printable archive failure reasons and rejects unsafe or oversized text', function (string $reason, bool $accepted): void {
    $node = Node::query()->create(['public_ssh_host' => '192.0.2.7', 'name' => 'cache-host', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit']);
    $settings = TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($node->id)]]);
    $agents = new class($reason) extends CacheAgentFixture
    {
        public function __construct(private readonly string $reason) {}

        public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null): array
        {
            return ['hashes' => [], 'failures' => ['archive' => $this->reason]];
        }
    };
    $cache = package_cache_transport($agents);
    $artifacts = [['manager' => 'composer', 'package' => 'acme/demo', 'version' => '1.0.0', 'url' => 'https://api.github.com/repos/acme/demo/zipball/'.str_repeat('a', 40), 'integrity' => null, 'key' => 'archive']];

    if ($accepted) {
        expect($cache->fill($settings->host($node->id), $node, $artifacts))->toBe(['hashes' => [], 'failures' => ['archive' => $reason]]);
    } else {
        expect(fn () => $cache->fill($settings->host($node->id), $node, $artifacts))->toThrow(TaskVmException::class, 'no verified archive hash or artifact failure');
    }
})->with([
    'fixed reason' => ['Archive checksum mismatch', true],
    'HTTP status' => ['HTTP 429', true],
    'printable boundary' => [str_repeat('x', 160), true],
    'empty' => ['', false],
    'newline' => ["HTTP 429\nsecret", false],
    'control byte' => ["Cache\0full", false],
    'non-ASCII' => ['échec', false],
    'oversized' => [str_repeat('x', 161), false],
]);

it('fails a package fill after one attempt and leaves the Project pending for its next fetch', function (): void {
    config(['queue.connections.gateway.driver' => 'database']);
    $node = Node::query()->create(['public_ssh_host' => '192.0.2.7', 'name' => 'cache-host', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit']);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($node->id)]]);
    $project = Project::query()->create(['repository_url' => 'https://github.com/acme/cache.git', 'name' => 'Cache', 'slug' => 'cache']);
    $snapshot = PackageCacheSnapshot::query()->create(['project_id' => $project->id, 'commit' => str_repeat('a', 40), 'blobs' => [], 'artifacts' => [], 'fill_pending' => true]);
    $agents = new class extends CacheAgentFixture
    {
        public int $calls = 0;

        public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null): array
        {
            $this->calls++;

            throw new AgentCommandRefused($command, 'task_vm.package_cache_failed', 'fill failed');
        }
    };
    app()->instance(TaskVmPackageCache::class, package_cache_transport($agents));
    FillTaskVmPackageCache::dispatch($node->id);

    /** @var Worker $worker */
    $worker = app('queue.worker');
    $worker->runNextJob('gateway', 'builds', new WorkerOptions(sleep: 0, maxTries: 3));

    expect($agents->calls)->toBe(1);
    expect(DB::table('jobs')->count())->toBe(0);
    expect($snapshot->fresh()->fill_pending)->toBeTrue();
    app(ObservePackageCacheAction::class)->execute($project, '/nonexistent-mirror', $snapshot->commit);
    expect(DB::table('jobs')->count())->toBe(1);
});

it('drops an overlapping fill without failure and fills a changed lock on the next fetch', function (): void {
    config(['queue.connections.gateway.driver' => 'database', 'queue.failed.driver' => 'database-uuids']);
    $root = TestOrbitHome::scratch('package-cache-overlap');
    mkdir($root, 0700, true);
    package_cache_git($root, ['init', '-b', 'main']);
    file_put_contents($root.'/composer.json', '{}');
    $lock = ['packages' => [['name' => 'acme/demo', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://api.github.com/repos/acme/demo/zipball/'.str_repeat('a', 40)]]]];
    file_put_contents($root.'/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
    $project = Project::query()->create(['repository_url' => 'https://github.com/acme/cache.git', 'name' => 'Cache', 'slug' => 'cache']);
    $node = Node::query()->create(['public_ssh_host' => '192.0.2.7', 'name' => 'cache-host', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit']);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($node->id)]]);
    $observe = app(ObservePackageCacheAction::class);
    $observe->execute($project, $root.'/.git', package_cache_commit($root));
    $snapshot = PackageCacheSnapshot::query()->findOrFail($project->id);
    $oldKey = $snapshot->artifacts[0]['key'];
    $failures = [];
    Event::listen(JobFailed::class, function (JobFailed $event) use (&$failures): void {
        $failures[] = $event->exception::class;
    });
    /** @var Worker $worker */
    $worker = app('queue.worker');
    $options = new WorkerOptions(sleep: 0, maxTries: 3);
    $agents = new class(function () use ($root, $lock, $project, $observe, $worker, $options): void {
        $lock['packages'][0]['version'] = '2.0.0';
        $lock['packages'][0]['dist']['url'] = 'https://api.github.com/repos/acme/demo/zipball/'.str_repeat('b', 40);
        file_put_contents($root.'/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
        $observe->execute($project, $root.'/.git', package_cache_commit($root));
        expect(DB::table('jobs')->count())->toBe(2);
        $worker->runNextJob('gateway', 'builds', $options);
    }) extends CacheAgentFixture
    {
        public array $inventories = [];

        public function __construct(private readonly Closure $duringFill) {}

        public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null): array
        {
            $payload = $input;
            $keys = array_column($payload['artifacts'], 'key');
            $this->inventories[] = $keys;
            if (count($this->inventories) === 1) {
                ($this->duringFill)();
            }

            return ['hashes' => array_fill_keys($keys, 'sha256-'.str_repeat('a', 64)), 'failures' => []];
        }
    };
    app()->instance(TaskVmPackageCache::class, package_cache_transport($agents));

    Artisan::call('queue:work', ['connection' => 'gateway', '--queue' => 'builds', '--once' => true, '--sleep' => 0, '--tries' => 3]);
    $this->travel(31)->seconds();
    $worker->runNextJob('gateway', 'builds', $options);

    expect($failures)->toBe([]);
    expect(DB::table('failed_jobs')->count())->toBe(0);
    expect(DB::table('jobs')->count())->toBe(0);
    expect($agents->inventories)->toBe([[$oldKey]]);
    expect($snapshot->fresh()->fill_pending)->toBeTrue();
    expect(PackageCacheEntry::query()->count())->toBe(0);
    $current = $snapshot->fresh();
    $newKey = $current->artifacts[0]['key'];
    expect($newKey)->not->toBe($oldKey);

    $observe->execute($project, '/nonexistent-mirror', $current->commit);
    expect(DB::table('jobs')->count())->toBe(1);
    $worker->runNextJob('gateway', 'builds', $options);

    expect($agents->inventories)->toBe([[$oldKey], [$newKey]]);
    expect($snapshot->fresh()->fill_pending)->toBeFalse();
    expect(PackageCacheEntry::query()->sole()->key)->toBe($newKey);
    expect($failures)->toBe([]);
    expect(DB::table('failed_jobs')->count())->toBe(0);
    expect(DB::table('jobs')->count())->toBe(0);
});
