<?php

declare(strict_types=1);

use App\Actions\TaskVms\ObservePackageCacheAction;
use App\Domain\TaskVms\TaskVmException;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeGuestAgentCommandRunner;
use Tests\Support\TaskVmSettingsBuilder;
use Tests\Support\TestOrbitHome;

/** @param list<string> $arguments */
function package_cache_git(string $root, array $arguments): string
{
    return trim(new Process(['git', '-C', $root, '-c', 'user.name=Cache', '-c', 'user.email=cache@example.test', ...$arguments])->mustRun()->getOutput());
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
    new UniqueLock(app(Repository::class))->release(new FillTaskVmPackageCache(31));
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

it('requests a fixed read-only mount before seeding the selected guest inventory', function (): void {
    $project = Project::query()->create(['repository_url' => 'https://github.com/acme/cache.git', 'name' => 'Cache', 'slug' => 'cache']);
    $node = Node::query()->create(['public_ssh_host' => '192.0.2.7', 'name' => 'cache-host', 'platform' => 'linux', 'architecture' => 'x86_64']);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($node->id)]]);
    $vm = TaskVm::query()->create(['host_node_id' => $node->id, 'provider' => 'incus', 'name' => 'tvm-77', 'state' => 'ready', 'wireguard_ip' => '10.44.0.130', 'pi_token' => 'private']);
    $artifacts = [['manager' => 'npm', 'package' => 'left-pad', 'version' => '1.3.0', 'url' => 'https://registry.npmjs.org/left-pad/-/left-pad-1.3.0.tgz', 'integrity' => null, 'key' => 'selected']];
    PackageCacheSnapshot::query()->create(['project_id' => $project->id, 'blobs' => [], 'artifacts' => $artifacts]);
    $agents = (new FakeAgentCommandRunner)->answer('package-cache.host', ['hashes' => [], 'failures' => []])->answer('incus.package-cache', ['ready' => true]);
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
    $agents = (new FakeAgentCommandRunner)->answer('package-cache.host', ['hashes' => [], 'failures' => []])->answerMalformed('incus.package-cache', $result);
    $guests = new FakeGuestAgentCommandRunner;

    expect(fn () => new TaskVmPackageCache($agents, $guests)->prepare($vm, $project))->toThrow(function (TaskVmException $error): void {
        expect($error->errorCode)->toBe('task_vm.package_cache_invalid_result');
    });
    expect($guests->calls)->toBe([]);
})->with(['missing' => [[]], 'false' => [['ready' => false]], 'mistyped' => [['ready' => 'true']]]);
