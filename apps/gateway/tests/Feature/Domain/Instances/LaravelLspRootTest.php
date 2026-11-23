<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceFolderPlan;

/** A workspace with the given files, and an Instance file in its place; returns the workspace. */
function laravelLspRootWorkspace(array $files, ?string $instanceFile = null): string
{
    $root = sys_get_temp_dir().'/laravel-lsp-root-'.bin2hex(random_bytes(4));
    $GLOBALS['laravelLspRoots'][] = $root;
    foreach ($files as $file) {
        @mkdir(dirname("{$root}/workspace/{$file}"), 0777, true);
        touch("{$root}/workspace/{$file}");
    }
    @mkdir("{$root}/workspace", 0777, true);
    if ($instanceFile !== null) {
        file_put_contents("{$root}/instance", str_replace('{workspace}', "{$root}/workspace", $instanceFile));
    }
    file_put_contents("{$root}/laravel-lsp-root", str_replace(InstanceContainer::InstanceFile, "{$root}/instance", InstanceFolderPlan::LaravelLspRoot));
    chmod("{$root}/laravel-lsp-root", 0755);

    return "{$root}/workspace";
}

/** Runs the program from `$cwd` with the given arguments: its exit code and its standard output. */
function laravelLspRoot(string $workspace, string $cwd, string ...$args): array
{
    $process = proc_open([dirname($workspace).'/laravel-lsp-root', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    $output = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);

    return [proc_close($process), $output];
}

afterEach(function (): void {
    foreach ($GLOBALS['laravelLspRoots'] ?? [] as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }
    $GLOBALS['laravelLspRoots'] = [];
});

it('starts at the application folder that the Instance file names, from anywhere in the workspace', function (): void {
    $workspace = laravelLspRootWorkspace(['apps/shop/artisan', 'apps/admin/artisan', 'README.md'], "ORBIT_NODE=beast\nORBIT_APPLICATION=\"{workspace}/apps/shop\"\n");

    expect(laravelLspRoot($workspace, $workspace))->toBe([0, "{$workspace}/apps/shop\n"])
        ->and(laravelLspRoot($workspace, '/', $workspace.'/apps/admin'))->toBe([0, "{$workspace}/apps/shop\n"]);
});

it('finds artisan at or above the given folder when the Instance file names no Laravel application', function (): void {
    $workspace = laravelLspRootWorkspace(['apps/shop/artisan', 'apps/shop/app/Http/Controllers/Controller.php', 'apps/admin/artisan'], "ORBIT_NODE=beast\n");

    expect(laravelLspRoot($workspace, $workspace.'/apps/shop/app/Http'))->toBe([0, "{$workspace}/apps/shop\n"])
        ->and(laravelLspRoot($workspace, '/', $workspace.'/apps/admin'))->toBe([0, "{$workspace}/apps/admin\n"]);
});

it('finds the one artisan below the workspace without an Instance file, and never one in vendor or node_modules', function (): void {
    $workspace = laravelLspRootWorkspace(['apps/shop/artisan', 'apps/shop/vendor/laravel/framework/artisan', 'node_modules/x/artisan']);

    expect(laravelLspRoot($workspace, $workspace))->toBe([0, "{$workspace}/apps/shop\n"]);
});

it('refuses a workspace with no artisan, or with two applications and nothing to pick one', function (array $files): void {
    $workspace = laravelLspRootWorkspace($files, "ORBIT_NODE=beast\n");

    expect(laravelLspRoot($workspace, $workspace))->toBe([1, '']);
})->with([
    'no artisan' => [['composer.json']],
    'two applications' => [['apps/shop/artisan', 'apps/admin/artisan']],
]);

it('puts the program in the runtime folder\'s bin, so a change to it is a new folder version', function (): void {
    $plan = new ReflectionMethod(InstanceFolderPlan::class, 'version');
    $folder = ['path' => '/opt/orbit-runtime', 'parts' => [], 'scripts' => ['laravel-lsp-root' => InstanceFolderPlan::LaravelLspRoot]];

    expect($plan->invoke(null, $folder))->not->toBe($plan->invoke(null, [...$folder, 'scripts' => []]));
});
