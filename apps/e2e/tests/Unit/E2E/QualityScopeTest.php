<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

pest()->group('subprocess');

/**
 * A repository with one project that Pint, Rector, and PHPStan check, committed on main.
 *
 * @return array{root: string, project: string, base: string, record: string}
 */
function qualityScopeFixture(): array
{
    $root = temporaryPath('orbit-quality-scope-', 6);
    $project = $root.'/apps/demo';
    $files = new Filesystem;
    foreach (['bin', 'apps/demo/app', 'apps/demo/config', 'apps/demo/tests', 'apps/demo/vendor/phpstan/phpstan', 'apps/demo/vendor/phpstan/cache', 'apps/demo/vendor/composer'] as $directory) {
        $files->makeDirectory($root.'/'.$directory, 0o700, true, true);
    }
    file_put_contents($project.'/vendor/composer/installed.json', json_encode(
        ['packages' => [['name' => 'phpstan/phpstan', 'version' => '2.3.1']]],
        JSON_THROW_ON_ERROR,
    ));
    copy(dirname(__DIR__, 5).'/bin/quality-scope', $root.'/bin/quality-scope');
    chmod($root.'/bin/quality-scope', 0o700);
    file_put_contents($root.'/.gitignore', "vendor/\n");
    file_put_contents($root.'/tool.php', "<?php\nfile_put_contents(getenv('QUALITY_SCOPE_RECORD'), json_encode(array_slice(\$argv, 1)));\n");
    file_put_contents($project.'/pint.json', json_encode([
        'preset' => 'laravel',
        'exclude' => ['storage'],
        'cache-file' => 'vendor/pint.cache',
        'notPath' => ['#^(?!(?:app|config|tests)/)#'],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($project.'/rector.php', <<<'PHP'
<?php

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/config',
    ]);
PHP);
    file_put_contents($project.'/phpstan.neon', "parameters:\n    level: 9\n    paths:\n        - app\n    tmpDir: vendor/phpstan/cache\n");
    file_put_contents($project.'/composer.json', "{}\n");
    file_put_contents($project.'/app/ParentClass.php', "<?php\n\nclass ParentClass {}\n");
    file_put_contents($project.'/app/Child.php', "<?php\n\nfinal class Child extends ParentClass {}\n");
    file_put_contents($project.'/app/Other.php', "<?php\n\nfinal class Other {}\n");
    file_put_contents($project.'/config/settings.php', "<?php\n\nreturn [];\n");
    file_put_contents($project.'/tests/ExampleTest.php', "<?php\n\nit('works', fn () => expect(true)->toBeTrue());\n");
    file_put_contents($project.'/app/view.blade.php', "<div></div>\n");
    file_put_contents($project.'/root.php', "<?php\n");

    foreach ([
        ['git', 'init', '--quiet', '--initial-branch=main'],
        ['git', 'config', 'user.name', 'Orbit test'],
        ['git', 'config', 'user.email', 'test@orbit.invalid'],
        ['git', 'config', 'gc.auto', '0'],
        ['git', 'add', '.'],
        ['git', 'commit', '--quiet', '-m', 'main'],
    ] as $arguments) {
        (new Process($arguments, $root))->mustRun();
    }

    return [
        'root' => $root,
        'project' => $project,
        'base' => trim((new Process(['git', 'rev-parse', 'HEAD'], $root))->mustRun()->getOutput()),
        'record' => $root.'/record.json',
    ];
}

/**
 * Write a framed PHPStan result cache whose dependency graph records the given dependents, with current hashes.
 *
 * @param  array<string, list<string>>  $dependents  project-relative file => files that depend on it
 */
function qualityScopeCache(string $project, array $dependents, ?string $frames = null, array $meta = []): void
{
    $paths = [];
    $id = static function (string $file) use (&$paths): int {
        $relative = '../../../'.$file;
        $index = array_search($relative, $paths, true);
        if ($index === false) {
            $paths[] = $relative;

            return count($paths) - 1;
        }

        return $index;
    };
    $entries = [];
    foreach ($dependents as $file => $users) {
        $path = $project.'/'.$file;
        $hash = is_file($path) ? hash_file('sha256', $path) : '';
        $entries[] = [$id($file), $hash, null, array_map($id, $users), null];
    }
    $graph = serialize(['paths' => $paths, 'entries' => $entries]);
    $meta = serialize([...['cacheVersion' => 'v22-valueDependencies', 'phpstanVersion' => '2.3.1', 'phpVersion' => PHP_VERSION_ID], ...$meta]);
    $frames ??= 'meta '.strlen($meta)."\n".$meta."errors* 1\n".strlen(serialize(['a' => 1]))."\n".serialize(['a' => 1])
        .'dependencyGraph '.strlen($graph)."\n".$graph;
    file_put_contents($project.'/vendor/phpstan/cache/resultCache.php', "<?php return; ?>\n".$frames);
}

/** The graph that PHPStan would record for the fixture's analysed files. */
function qualityScopeCurrentCache(string $project): void
{
    qualityScopeCache($project, array_filter([
        'app/ParentClass.php' => ['app/Child.php'],
        'app/Child.php' => [],
        'app/Other.php' => [],
        'app/Added.php' => [],
    ], static fn (string $file): bool => is_file($project.'/'.$file), ARRAY_FILTER_USE_KEY));
}

/**
 * @param  array<string, string|false>  $environment
 * @return array{process: Process, arguments: list<string>|null}
 */
function qualityScopeRun(array $fixture, string $tool, array $environment = []): array
{
    if (is_file($fixture['record'])) {
        unlink($fixture['record']);
    }
    $process = new Process(
        [PHP_BINARY, $fixture['root'].'/bin/quality-scope', $tool, PHP_BINARY, $fixture['root'].'/tool.php', '--test'],
        $fixture['project'],
        [
            'ORBIT_CHECK_SCOPE' => 'changed',
            'ORBIT_CHECK_BASE' => $fixture['base'],
            'QUALITY_SCOPE_RECORD' => $fixture['record'],
            'CI' => false,
            'GITHUB_ACTIONS' => false,
            ...$environment,
        ],
    );
    $process->setTimeout(30);
    $process->run();
    $arguments = is_file($fixture['record']) ? json_decode((string) file_get_contents($fixture['record']), true) : null;

    return ['process' => $process, 'arguments' => $arguments];
}

/** Commit one change, stage another, and leave one file untracked. */
function qualityScopeChange(array $fixture): void
{
    file_put_contents($fixture['project'].'/app/ParentClass.php', "<?php\n\nclass ParentClass\n{\n    public string \$name = '';\n}\n");
    (new Process(['git', 'commit', '--quiet', '-am', 'change parent'], $fixture['root']))->mustRun();
    file_put_contents($fixture['project'].'/tests/ExampleTest.php', "<?php\n\nit('still works', fn () => expect(true)->toBeTrue());\n");
    (new Process(['git', 'add', 'apps/demo/tests/ExampleTest.php'], $fixture['root']))->mustRun();
    file_put_contents($fixture['project'].'/tests/NewTest.php', "<?php\n\nit('is new', fn () => expect(true)->toBeTrue());\n");
    file_put_contents($fixture['project'].'/app/view.blade.php', "<div>changed</div>\n");
    file_put_contents($fixture['project'].'/root.php', "<?php\n// changed\n");
}

it('runs the command unchanged outside the candidate gate', function (): void {
    $fixture = qualityScopeFixture();
    qualityScopeChange($fixture);

    $run = qualityScopeRun($fixture, 'rector', ['ORBIT_CHECK_SCOPE' => false]);

    expect($run['process']->getExitCode())->toBe(0, $run['process']->getErrorOutput())
        ->and($run['arguments'])->toBe(['--test'])
        ->and($run['process']->getErrorOutput())->toBe('');
});

it('checks with Pint only the changed PHP files that pint.json formats', function (): void {
    $fixture = qualityScopeFixture();
    qualityScopeChange($fixture);

    $run = qualityScopeRun($fixture, 'pint');

    expect($run['process']->getExitCode())->toBe(0, $run['process']->getErrorOutput())
        ->and($run['arguments'])->toBe(['--test', 'app/ParentClass.php', 'tests/ExampleTest.php', 'tests/NewTest.php'])
        ->and($run['process']->getErrorOutput())->toContain('Pint: checking 3 changed PHP files');
});

it('skips Pint with a log line when no changed PHP file needs it', function (): void {
    $fixture = qualityScopeFixture();
    file_put_contents($fixture['project'].'/app/view.blade.php', "<div>changed</div>\n");

    $run = qualityScopeRun($fixture, 'pint');

    expect($run['process']->getExitCode())->toBe(0)
        ->and($run['arguments'])->toBeNull()
        ->and($run['process']->getErrorOutput())->toContain('Pint: no changed PHP files to check');
});

it('checks with Rector the changed files, their dependents, and the files PHPStan does not analyse', function (): void {
    $fixture = qualityScopeFixture();
    qualityScopeChange($fixture);
    qualityScopeCurrentCache($fixture['project']);

    $run = qualityScopeRun($fixture, 'rector');

    expect($run['process']->getExitCode())->toBe(0, $run['process']->getErrorOutput())
        ->and($run['arguments'])->toBe(['--test', 'app/Child.php', 'app/ParentClass.php', 'config/settings.php'])
        ->and($run['process']->getErrorOutput())
        ->toContain('Rector: checking 3 files: 1 changed, 1 dependent, 1 not analysed by PHPStan');
});

it('runs Rector on every file when the change deletes an analysed PHP file', function (array $recorded): void {
    $fixture = qualityScopeFixture();
    unlink($fixture['project'].'/app/ParentClass.php');
    // After PHPStan runs again, the deleted file may no longer be in the graph at all.
    qualityScopeCache($fixture['project'], [...$recorded, 'app/Child.php' => [], 'app/Other.php' => []]);

    $run = qualityScopeRun($fixture, 'rector');

    expect($run['process']->getExitCode())->toBe(0, $run['process']->getErrorOutput())
        ->and($run['arguments'])->toBe(['--test'])
        ->and($run['process']->getErrorOutput())->toContain('Rector: checking every file: the change deletes app/ParentClass.php');
})->with([
    'recorded with its dependents' => [['app/ParentClass.php' => ['app/Child.php']]],
    'no longer recorded' => [[]],
]);

it('checks with Rector the dependents of a changed file outside the project', function (): void {
    $fixture = qualityScopeFixture();
    mkdir($fixture['root'].'/packages/sdk', 0o700, true);
    file_put_contents($fixture['root'].'/packages/sdk/Thing.php', "<?php\n\nfinal class Thing {}\n");
    qualityScopeCache($fixture['project'], [
        '../../packages/sdk/Thing.php' => ['app/Other.php'],
        'app/ParentClass.php' => ['app/Child.php'],
        'app/Child.php' => [],
        'app/Other.php' => [],
    ]);

    $run = qualityScopeRun($fixture, 'rector');

    expect($run['process']->getExitCode())->toBe(0, $run['process']->getErrorOutput())
        ->and($run['arguments'])->toBe(['--test', 'app/Other.php', 'config/settings.php']);
});

it('runs Rector on every file when it cannot prove which files a change affects', function (Closure $prepare, string $reason): void {
    $fixture = qualityScopeFixture();
    qualityScopeChange($fixture);
    qualityScopeCurrentCache($fixture['project']);
    $prepare($fixture);

    $run = qualityScopeRun($fixture, 'rector');

    expect($run['process']->getExitCode())->toBe(0, $run['process']->getErrorOutput())
        ->and($run['arguments'])->toBe(['--test'])
        ->and($run['process']->getErrorOutput())->toContain('Rector: checking every file: '.$reason);
})->with([
    'missing result cache' => [function (array $fixture): void {
        unlink($fixture['project'].'/vendor/phpstan/cache/resultCache.php');
    }, 'the PHPStan result cache is missing'],
    'unreadable result cache' => [function (array $fixture): void {
        file_put_contents($fixture['project'].'/vendor/phpstan/cache/resultCache.php', "<?php return [];\n");
    }, 'the PHPStan result cache has an unknown format'],
    'result cache without metadata' => [function (array $fixture): void {
        qualityScopeCache($fixture['project'], [], "meta 4\nb:0;");
    }, 'the PHPStan result cache metadata cannot be read'],
    'result cache without a graph' => [function (array $fixture): void {
        $meta = serialize(['cacheVersion' => 'v22-valueDependencies', 'phpstanVersion' => '2.3.1']);
        qualityScopeCache($fixture['project'], [], 'meta '.strlen($meta)."\n".$meta);
    }, 'the PHPStan result cache records no dependencies'],
    'unknown cache format' => [function (array $fixture): void {
        qualityScopeCache($fixture['project'], ['app/ParentClass.php' => ['app/Child.php'], 'app/Child.php' => [], 'app/Other.php' => []],
            meta: ['cacheVersion' => 'v99-future']);
    }, "the PHPStan result cache format 'v99-future' is not one this script reads"],
    'cache from another PHPStan' => [function (array $fixture): void {
        qualityScopeCache($fixture['project'], ['app/ParentClass.php' => ['app/Child.php'], 'app/Child.php' => [], 'app/Other.php' => []],
            meta: ['phpstanVersion' => '2.2.0']);
    }, "the PHPStan result cache was written by PHPStan '2.2.0', not the installed '2.3.1'"],
    'stale result cache' => [function (array $fixture): void {
        file_put_contents($fixture['project'].'/app/Other.php', "<?php\n\nfinal class Other { }\n");
    }, 'the PHPStan result cache is older than app/Other.php'],
    'file PHPStan has not analysed yet' => [function (array $fixture): void {
        file_put_contents($fixture['project'].'/app/Unrecorded.php', "<?php\n\nfinal class Unrecorded {}\n");
    }, 'the PHPStan result cache does not record app/Unrecorded.php'],
    'changed configuration' => [function (array $fixture): void {
        file_put_contents($fixture['project'].'/rector.php', "\n", FILE_APPEND);
    }, 'the change touches rector.php'],
    'changed lock file' => [function (array $fixture): void {
        file_put_contents($fixture['project'].'/composer.lock', "{}\n");
    }, 'the change touches composer.lock'],
]);

it('runs Pint on every file in CI, without a merge base, and for a changed configuration', function (Closure $prepare, array $environment, string $reason): void {
    $fixture = qualityScopeFixture();
    qualityScopeChange($fixture);
    $prepare($fixture);

    $run = qualityScopeRun($fixture, 'pint', $environment);

    expect($run['process']->getExitCode())->toBe(0, $run['process']->getErrorOutput())
        ->and($run['arguments'])->toBe(['--test'])
        ->and($run['process']->getErrorOutput())->toContain('Pint: checking every file: '.$reason);
})->with([
    'CI' => [fn (array $fixture) => null, ['CI' => 'true'], 'CI checks every file'],
    'GitHub Actions' => [fn (array $fixture) => null, ['GITHUB_ACTIONS' => 'true'], 'CI checks every file'],
    'no merge base' => [fn (array $fixture) => null, ['ORBIT_CHECK_BASE' => ''], 'the gate has no merge base with main'],
    'changed Pint configuration' => [function (array $fixture): void {
        file_put_contents($fixture['project'].'/pint.json', "\n", FILE_APPEND);
    }, [], 'the change touches pint.json'],
]);
