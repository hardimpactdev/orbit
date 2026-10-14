<?php

declare(strict_types=1);

use App\Actions\TaskVms\ObservePackageCacheAction;
use App\Infrastructure\TaskVms\NpmPackageCache;
use App\Jobs\TaskVms\FillTaskVmPackageCache;
use App\Models\PackageCacheSnapshot;
use App\Models\Project;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Support\TaskVmSettingsBuilder;
use Tests\Support\TestOrbitHome;

pest()->group('subprocess');

it('reads public registry packages from npm, Bun JSONC and pnpm without treating workspace links as artifacts', function (string $filename, string $contents): void {
    $artifacts = (new NpmPackageCache)->read($filename, $contents);

    expect($artifacts)->toHaveCount(1);
    expect($artifacts[0])->toMatchArray(['manager' => 'npm', 'package' => '@acme/demo', 'version' => '1.2.3', 'url' => 'https://registry.npmjs.org/@acme/demo/-/demo-1.2.3.tgz', 'integrity' => 'sha512-ZGVtbw==']);
})->with([
    'npm' => ['package-lock.json', '{"lockfileVersion":3,"packages":{"":{},"node_modules/@acme/demo":{"version":"1.2.3","resolved":"https://registry.npmjs.org/@acme/demo/-/demo-1.2.3.tgz","integrity":"sha512-ZGVtbw=="},"node_modules/local":{"link":true}}}'],
    'bun' => ['bun.lock', '{// text lock
"lockfileVersion":2,"packages":{"alias":["@acme/demo@1.2.3","",{},"sha512-ZGVtbw=="],"local":["workspace:packages/local"],},}'],
    'pnpm' => ['pnpm-lock.yaml', "lockfileVersion: '9.0'\npackages:\n  '@acme/demo@1.2.3':\n    resolution:\n      integrity: sha512-ZGVtbw==\n"],
]);

it('skips credentials, private registries, missing integrity and unsafe package names', function (array $package): void {
    $lock = json_encode(['packages' => ['node_modules/demo' => $package]], JSON_THROW_ON_ERROR);

    expect((new NpmPackageCache)->read('package-lock.json', $lock))->toBe([]);
})->with([
    [['name' => 'demo', 'version' => '1.2.3', 'resolved' => 'https://token@registry.npmjs.org/demo/-/demo-1.2.3.tgz', 'integrity' => 'sha512-ZGVtbw==']],
    [['name' => 'demo', 'version' => '1.2.3', 'resolved' => 'https://registry.internal/demo/-/demo-1.2.3.tgz', 'integrity' => 'sha512-ZGVtbw==']],
    [['name' => 'demo', 'version' => '1.2.3', 'resolved' => 'https://registry.npmjs.org/demo/-/demo-1.2.3.tgz']],
    [['name' => '../demo', 'version' => '1.2.3', 'resolved' => 'https://registry.npmjs.org/demo/-/demo-1.2.3.tgz', 'integrity' => 'sha512-ZGVtbw==']],
]);

it('seeds real npm and Bun caches and downloads cache misses through a registry', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/package-cache/npm.py'), resource_path('task-vms/package-cache.py'), resource_path('task-vms/package-cache-seed.py')]);
    $process->setTimeout(90)->run();

    expect($process->getErrorOutput())->toContain('OK');
    expect($process->isSuccessful())->toBeTrue();
});

it('tracks changed JavaScript locks below packages and clears usage on default-branch deletion', function (): void {
    $root = TestOrbitHome::scratch('npm-cache-git');
    mkdir($root.'/packages/web', 0700, true);
    (new Process(['git', '-C', $root, 'init', '-b', 'main']))->mustRun();
    $project = Project::query()->create(['repository_url' => 'https://github.com/acme/cache.git', 'name' => 'Web', 'slug' => 'web', 'default_branch' => 'main']);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host(31)]]);
    Queue::fake([FillTaskVmPackageCache::class]);
    file_put_contents($root.'/packages/web/package-lock.json', '{"lockfileVersion":3,"packages":{"node_modules/demo":{"version":"1.2.3","resolved":"https://registry.npmjs.org/demo/-/demo-1.2.3.tgz","integrity":"sha512-ZGVtbw=="}}}');
    (new Process(['git', '-C', $root, 'add', '.']))->mustRun();
    (new Process(['git', '-C', $root, '-c', 'user.name=t', '-c', 'user.email=t@example.test', 'commit', '--quiet', '-m', 'lock']))->mustRun();
    $commit = trim((new Process(['git', '-C', $root, 'rev-parse', 'HEAD']))->mustRun()->getOutput());
    $observe = app(ObservePackageCacheAction::class);

    $observe->execute($project, $root.'/.git', $commit);

    $snapshot = PackageCacheSnapshot::query()->findOrFail($project->id);
    expect($snapshot->artifacts[0]['package'])->toBe('demo');
    Queue::assertPushed(FillTaskVmPackageCache::class, 1);

    $observe->execute($project, $root.'/.git', null);

    expect($snapshot->fresh()?->artifacts)->toBe([]);
    Queue::assertPushed(FillTaskVmPackageCache::class, 1);
});

it('reads an empty pnpm inventory when the last registry dependency was removed', function (): void {
    expect((new NpmPackageCache)->read('pnpm-lock.yaml', "lockfileVersion: '9.0'\nimporters:\n  .: {}\n"))->toBe([]);
});
