<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

pest()->group('subprocess');

/** @return list<string> */
function orb277_projects(): array
{
    return ['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'];
}

/** @return list<string> */
function orb277_selected_projects(): array
{
    return ['repository', ...orb277_projects()];
}

/**
 * Print the TIA directory a project's guidance configuration resolves, without the parent run's Pest state.
 */
function orb277_tia_directory(string $root, string|false $override): string
{
    $process = new Process(['vendor/bin/pest', '--configuration=phpunit.guidance.xml', '--baseline'], $root, [
        'COLLISION_PRINTER' => false,
        'ORBIT_TIA_DIRECTORY' => $override,
        'PARATEST' => false,
        'PEST_TIA' => false,
        'PEST_TIA_BASELINED' => false,
        'PEST_TIA_FILTERED' => false,
        'PEST_TIA_LOCALLY' => false,
        'TEST_TOKEN' => false,
        'UNIQUE_TEST_TOKEN' => false,
    ]);
    $process->setTimeout(120);
    $process->mustRun();
    $output = trim($process->getOutput());
    $compact = json_decode($output, true);

    return is_array($compact) && is_string($compact['raw'][0] ?? null) ? $compact['raw'][0] : $output;
}

/** @return array{root: string, path: string} */
function orb277_gate_fixture(?string $candidatePath = null): array
{
    $root = temporaryPath('orbit-builder-gate-', 6);

    mkdir($root.'/bin', 0o700, true);
    mkdir($root.'/tooling', 0o700, true);
    mkdir($root.'/apps/web/src/api', 0o700, true);
    mkdir($root.'/apps/web/node_modules/.bin', 0o700, true);
    mkdir($root.'/apps/pi-server', 0o700, true);
    mkdir($root.'/docs', 0o700, true);
    file_put_contents($root.'/apps/web/src/api/schema.d.ts', "export type Example = string;\n");
    file_put_contents($root.'/docs/openapi.json', "{}\n");

    // A gate that runs Pest fails: CI runs the test suites.
    foreach (orb277_projects() as $project) {
        mkdir($root.'/'.$project.'/vendor/bin', 0o700, true);
        file_put_contents($root.'/'.$project.'/.gitkeep', '');
        file_put_contents($root.'/'.$project.'/vendor/bin/pest', "#!/usr/bin/env sh\necho 'The candidate gate runs no tests.'\nexit 1\n");
        chmod($root.'/'.$project.'/vendor/bin/pest', 0o700);
    }

    mkdir($root.'/tools', 0700, true);
    copy(base_path('../../tools/check_budget.py'), $root.'/tools/check_budget.py');
    copy(base_path('../../bin/review-check'), $root.'/bin/review-check');
    copy(base_path('../../bin/check-processes'), $root.'/bin/check-processes');
    copy(base_path('../../bin/check-classification-fakes'), $root.'/bin/check-classification-fakes');
    file_put_contents($root.'/bin/docs-impact', "#!/usr/bin/env sh\nexit 0\n");
    file_put_contents($root.'/bin/project-vocabulary', "#!/usr/bin/env sh\nexit 0\n");
    file_put_contents($root.'/bin/env-docs', "#!/usr/bin/env sh\nexit 0\n");
    file_put_contents($root.'/bin/tooling-baseline', "#!/usr/bin/env sh\nexit 0\n");
    file_put_contents($root.'/bin/docs-nav', "#!/usr/bin/env sh\nexit 0\n");
    foreach (['docs-openapi', 'mcp-tools', 'api-fixtures'] as $generator) {
        file_put_contents($root.'/bin/'.$generator, "#!/usr/bin/env sh\nexit 0\n");
        chmod($root.'/bin/'.$generator, 0o700);
    }
    mkdir($root.'/apps/web/dist', 0o700, true);
    file_put_contents($root.'/apps/web/dist/index.html', '<html></html>');
    file_put_contents($root.'/apps/web/dist/version.json', '{}');
    copy(base_path('tests/Fixtures/BuilderGate/composer'), $root.'/tooling/composer');
    foreach (['bun', 'vp'] as $tool) {
        file_put_contents($root.'/tooling/'.$tool, <<<'SH'
#!/usr/bin/env sh
if [ "$1" = install ] && [ "${ORBIT_GATE_FAIL_INSTALL:-}" = "$PWD" ]; then
    echo 'bun install failed'
    exit 9
fi
exit 0
SH);

        chmod($root.'/tooling/'.$tool, 0o700);
    }
    file_put_contents($root.'/apps/web/node_modules/.bin/openapi-typescript', <<<'SH'
#!/usr/bin/env sh
while [ "$#" -gt 0 ]; do
    if [ "$1" = "-o" ]; then
        cp src/api/schema.d.ts "$2"
        exit $?
    fi
    shift
done
exit 2
SH);
    chmod($root.'/apps/web/node_modules/.bin/openapi-typescript', 0o700);
    chmod($root.'/bin/review-check', 0o700);
    chmod($root.'/bin/check-processes', 0o700);
    chmod($root.'/bin/check-classification-fakes', 0o700);
    chmod($root.'/bin/docs-impact', 0o700);
    chmod($root.'/bin/project-vocabulary', 0o700);
    chmod($root.'/bin/env-docs', 0o700);
    chmod($root.'/bin/tooling-baseline', 0o700);
    chmod($root.'/bin/docs-nav', 0o700);
    chmod($root.'/tooling/composer', 0o700);

    foreach ([
        ['git', 'init', '--quiet', '--initial-branch=main'],
        ['git', 'config', 'user.name', 'Orbit test'],
        ['git', 'config', 'user.email', 'test@orbit.invalid'],
        ['git', 'add', '.'],
        ['git', 'commit', '--quiet', '-m', 'main'],
    ] as $arguments) {
        (new Process($arguments, $root))->mustRun();
    }

    $candidatePath ??= 'apps/gateway/app/Example.php';
    if ($candidatePath === 'apps/gateway/app/Example.php') {
        mkdir($root.'/apps/gateway/app', 0o700, true);
        if (! is_dir($root.'/apps/gateway/tests')) {
            mkdir($root.'/apps/gateway/tests', 0o700, true);
        }
        file_put_contents($root.'/apps/gateway/app/Example.php', "<?php\n\nfinal class Example {}\n");
        file_put_contents($root.'/apps/gateway/tests/ExampleTest.php', "<?php\n\nit('exists', fn () => expect(true)->toBeTrue());\n");
    } elseif ($candidatePath === 'docs/openapi.json') {
        file_put_contents($root.'/'.$candidatePath, "{\"changed\": true}\n");
    } else {
        $path = $root.'/'.$candidatePath;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o700, true);
        }
        file_put_contents($path, "candidate change\n");
    }

    foreach ([
        ['git', 'checkout', '--quiet', '-b', 'orb-277-candidate'],
        ['git', 'add', '.'],
        ['git', 'commit', '--quiet', '-m', 'candidate'],
    ] as $arguments) {
        (new Process($arguments, $root))->mustRun();
    }

    return [
        'root' => $root,
        'path' => $root.'/tooling:'.getenv('PATH'),
    ];
}

/** @return array{process: Process, receipt: array<string, mixed>} */
function orb277_run_gate(array $fixture, string $failInstall = '', int $expectedExit = 0): array
{
    $process = new Process([$fixture['root'].'/bin/review-check'], $fixture['root'], [
        'ORBIT_GATE_FAIL_INSTALL' => $failInstall,
        'PATH' => $fixture['path'],
        // One process: the projects run one after another.
        'ORBIT_CHECK_PROCESSES' => '1',
        'ORBIT_CHECK_SCOPE' => false,
    ]);
    $process->setTimeout(60);
    $process->run();

    $head = trim((new Process(['git', 'rev-parse', 'HEAD'], $fixture['root']))->mustRun()->getOutput());
    $paths = glob("{$fixture['root']}/.git/orbit-checks/{$head}/review-*/result.json");

    expect($process->getExitCode())->toBe($expectedExit, $process->getOutput().$process->getErrorOutput());
    expect($paths)->toBeArray()->toHaveCount(1);
    expect(fileperms(dirname($paths[0])) & 0070)->toBe(0050);

    return [
        'process' => $process,
        'receipt' => json_decode((string) file_get_contents($paths[0]), true, flags: JSON_THROW_ON_ERROR),
    ];
}

describe('Builder gate', function (): void {
    it('removes its own reports older than a day from the shared Git directory before it writes a new one', function (): void {
        $fixture = orb277_gate_fixture();
        $checks = $fixture['root'].'/.git/orbit-checks';
        $old = $checks.'/'.str_repeat('a', 40);
        $recent = $checks.'/'.str_repeat('b', 40);
        $empty = $checks.'/'.str_repeat('c', 40);
        mkdir($old.'/review-old/nested', 0o700, true);
        file_put_contents($old.'/review-old/result.json', '{}');
        mkdir($recent.'/review-recent', 0o750, true);
        mkdir($empty, 0o750, true);
        $dayAgo = time() - 25 * 60 * 60;
        foreach ([$old.'/review-old/nested', $old.'/review-old', $old, $empty] as $path) {
            touch($path, $dayAgo);
        }

        expect(orb277_run_gate($fixture)['receipt']['passed'])->toBeTrue()
            ->and(file_exists($old.'/review-old'))->toBeFalse()
            ->and(is_dir($recent.'/review-recent'))->toBeTrue()
            ->and(file_exists($empty))->toBeFalse()
            // Removing the old report touches its HEAD directory, so that directory goes on a later run.
            ->and(is_dir($old))->toBeTrue();
    });

    it('selects the web profile for web changes', function (): void {
        $fixture = orb277_gate_fixture('apps/web/src/App.tsx');
        $receipt = orb277_run_gate($fixture)['receipt'];
        $web = collect($receipt['checks'])->where('project', 'apps/web')->values();

        expect($receipt['passed'])->toBeTrue()
            ->and($receipt['changed_paths'])->toBe(['apps/web/src/App.tsx'])
            ->and(collect($receipt['checks'])->pluck('project')->unique()->values()->all())
            ->toBe([...orb277_selected_projects(), 'apps/web'])
            ->and($web->pluck('command')->all())->toHaveCount(5)
            ->and($web[0]['command'])->toBe(['bun', 'install', '--frozen-lockfile'])
            ->and($web[1]['command'])->toBe(['bun', 'run', 'check'])
            ->and($web[2]['command'])->toBe(['bun', 'run', 'build'])
            ->and($web->pluck('exit_code')->all())->toBe([0, 0, 0, 0, 0]);
        expect($web[3]['command'][2])->toContain('test -s dist/index.html', 'test -s dist/version.json');
        expect($web[4]['command'][0])->toBe('bash')
            ->and($web[4]['command'][2])->toContain('./node_modules/.bin/openapi-typescript');
    });

    it('rejects stale web types with the regeneration command', function (): void {
        $fixture = orb277_gate_fixture('docs/openapi.json');
        file_put_contents($fixture['root'].'/apps/web/node_modules/.bin/openapi-typescript', <<<'SH'
#!/usr/bin/env sh
while [ "$#" -gt 0 ]; do
    if [ "$1" = "-o" ]; then
        echo 'export type Changed = number;' > "$2"
        exit 0
    fi
    shift
done
exit 2
SH);

        $run = orb277_run_gate($fixture, expectedExit: 1);
        $check = collect($run['receipt']['checks'])->first(
            static fn (array $check): bool => $check['project'] === 'apps/web'
                && str_contains(implode(' ', $check['command']), 'openapi-typescript'),
        );

        expect($run['receipt']['passed'])->toBeFalse();
        expect(file_get_contents($check['log']))->toContain(
            'Run: cd apps/web && bun run types',
            '--- src/api/schema.d.ts',
            '+++ generated/schema.d.ts',
            '-export type Example = string;',
            '+export type Changed = number;',
        );
        expect(file_get_contents($fixture['root'].'/apps/web/src/api/schema.d.ts'))
            ->toBe("export type Example = string;\n");
    });

    it('rejects a web build that leaves out a CI artifact', function (string $artifact): void {
        $fixture = orb277_gate_fixture('apps/web/src/App.tsx');
        unlink($fixture['root'].'/apps/web/dist/'.$artifact);

        $run = orb277_run_gate($fixture, expectedExit: 1);
        $check = collect($run['receipt']['checks'])->first(
            static fn (array $check): bool => $check['project'] === 'apps/web'
                && str_contains(implode(' ', $check['command']), 'test -s dist/index.html'),
        );

        expect($run['receipt']['passed'])->toBeFalse();
        expect($check['exit_code'])->toBe(1);
    })->with(['index.html', 'version.json']);

    it('selects only the generated web types check for OpenAPI changes', function (): void {
        $fixture = orb277_gate_fixture('docs/openapi.json');
        $receipt = orb277_run_gate($fixture)['receipt'];
        $web = collect($receipt['checks'])->where('project', 'apps/web')->values();

        expect($receipt['passed'])->toBeTrue()
            ->and($receipt['changed_paths'])->toBe(['docs/openapi.json'])
            ->and(collect($receipt['checks'])->pluck('project')->unique()->values()->all())->toBe([...orb277_selected_projects(), 'apps/web'])
            ->and($web)->toHaveCount(2)
            ->and($web[0]['command'])->toBe(['bun', 'install', '--frozen-lockfile'])
            ->and($web[1]['command'][2])->toContain('./node_modules/.bin/openapi-typescript')
            ->and($web->pluck('exit_code')->all())->toBe([0, 0]);
    });

    it('runs every PHP project for documentation-only and tools changes', function (string $path, int $expectedChecks): void {
        $fixture = orb277_gate_fixture($path);
        $receipt = orb277_run_gate($fixture)['receipt'];

        expect($receipt['passed'])->toBeTrue()
            ->and($receipt['changed_paths'])->toBe([$path])
            ->and(collect($receipt['checks'])->pluck('project')->unique()->values()->all())->toBe(orb277_selected_projects())
            ->and(collect($receipt['checks'])->count())->toBe($expectedChecks);
    })->with([
        'documentation-only change' => ['docs/reference/example.md', 15],
        'tools change' => ['tools/phpstan/NoInlineVarOverrideRule.php', 15],
    ]);

    it('selects the Pi server CI profile for Pi server changes', function (): void {
        $fixture = orb277_gate_fixture('apps/pi-server/src/index.ts');
        $receipt = orb277_run_gate($fixture)['receipt'];
        $piServer = collect($receipt['checks'])->where('project', 'apps/pi-server')->values();

        expect($receipt['passed'])->toBeTrue()
            ->and($receipt['changed_paths'])->toBe(['apps/pi-server/src/index.ts'])
            ->and(collect($receipt['checks'])->pluck('project')->unique()->values()->all())->toBe([...orb277_selected_projects(), 'apps/pi-server'])
            ->and($piServer->pluck('command')->all())->toBe([
                ['bun', 'install', '--frozen-lockfile'],
                ['bun', 'run', 'check'],
                ['bun', 'run', 'build'],
            ])
            ->and($piServer->pluck('exit_code')->all())->toBe([0, 0, 0]);
    });

    it('fails with the required tool name when a selected check tool is missing', function (): void {
        $fixture = orb277_gate_fixture('apps/web/src/App.tsx');
        unlink($fixture['root'].'/tooling/bun');
        $fixture['path'] = $fixture['root'].'/tooling:/usr/bin:/bin';
        $run = orb277_run_gate($fixture, expectedExit: 1);
        $install = collect($run['receipt']['checks'])->first(
            static fn (array $check): bool => $check['project'] === 'apps/web'
                && $check['command'] === ['bun', 'install', '--frozen-lockfile'],
        );

        expect($run['receipt']['passed'])->toBeFalse()
            ->and($install)->toMatchArray(['exit_code' => 127])
            ->and(file_get_contents($install['log']))->toContain('bun: required tool not found');
    });

    it('records web dependency installation failures in the receipt', function (): void {
        $fixture = orb277_gate_fixture('apps/web/src/App.tsx');
        $run = orb277_run_gate($fixture, $fixture['root'].'/apps/web', 1);
        $webInstall = collect($run['receipt']['checks'])->first(
            static fn (array $check): bool => $check['project'] === 'apps/web'
                && $check['command'] === ['bun', 'install', '--frozen-lockfile'],
        );

        expect($run['receipt']['passed'])->toBeFalse()
            ->and($webInstall)->toMatchArray(['exit_code' => 9])
            ->and(file_get_contents($webInstall['log']))->toContain('bun install failed');
    });

    it('runs the candidate gate from root composer check without a process timeout', function (): void {
        $composer = json_decode((string) file_get_contents(base_path('../../composer.json')), true, flags: JSON_THROW_ON_ERROR);

        expect($composer['scripts']['check'])->toBe(['Composer\\Config::disableProcessTimeout', 'bin/review-check']);
    });

    it('records guidance checks into a separate TIA cache in every project', function (string $project): void {
        $repository = realpath(base_path('../..'));
        $root = $repository.'/'.$project;
        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $guidance = $root.'/vendor/.orbit-guidance-tia';

        expect($composer['scripts']['guidance:check'])->toBe('../../bin/guidance-check');
        expect(orb277_tia_directory($root, 'vendor/.orbit-guidance-tia'))->toBe($guidance);
        expect(orb277_tia_directory($root, false))->not->toStartWith($guidance);

        $ignored = new Process(['git', 'check-ignore', '-q', $project.'/vendor/.orbit-guidance-tia/graph.json'], $repository);
        $ignored->run();

        expect($ignored->getExitCode())->toBe(0, 'The guidance cache must stay out of the candidate tree.');
    })->with(orb277_projects());

    it('checks changed Pest setup and helper sources for guarded classification fakes', function (): void {
        $fixture = orb277_gate_fixture();
        $root = $fixture['root'];
        file_put_contents($root.'/apps/gateway/tests/Pest.php', <<<'PHP'
<?php
use Laravel\Ai\Classification;
Classification::fake(fn (): array => [])->preventStrayClassifications();
PHP);
        mkdir($root.'/apps/gateway/tests/Support', 0o700, true);
        file_put_contents($root.'/apps/gateway/tests/Support/ClassificationFixtures.php', <<<'PHP'
<?php
\Laravel\Ai\Classification::fake(function (): array {
    return [];
})->preventStrayClassifications();
PHP);

        $receipt = orb277_run_gate($fixture)['receipt'];
        $check = collect($receipt['checks'])->first(
            static fn (array $check): bool => ($check['command'][1] ?? null) === '../../bin/check-classification-fakes',
        );

        expect($receipt['passed'])->toBeTrue()
            ->and($check['command'] ?? [])->toBe([
                'php', '../../bin/check-classification-fakes', 'tests/ExampleTest.php', 'tests/Pest.php',
                'tests/Support/ClassificationFixtures.php',
            ]);
    });

    it('runs no test suite, whatever the candidate changes', function (): void {
        $fixture = orb277_gate_fixture('apps/pi-server/src/index.ts');
        foreach (orb277_projects() as $project) {
            if (! is_dir($fixture['root'].'/'.$project.'/tests')) {
                mkdir($fixture['root'].'/'.$project.'/tests', 0o700, true);
            }
            file_put_contents($fixture['root'].'/'.$project.'/tests/ChangedTest.php', "<?php\n\nit('changed', fn () => expect(true)->toBeTrue());\n");
        }
        file_put_contents($fixture['root'].'/apps/web/src/App.tsx', "export {};\n");

        $receipt = orb277_run_gate($fixture)['receipt'];
        $commands = collect($receipt['checks'])->map(static fn (array $check): string => implode(' ', $check['command']));

        expect($receipt['passed'])->toBeTrue()
            ->and($receipt)->not->toHaveKeys(['test_base', 'warnings'])
            ->and($commands->filter(static fn (string $command): bool => preg_match('/\bpest\b|\btest:affected\b|--no-tia|^bun run test\b/', $command) === 1)->all())
            ->toBe([]);
    });
});
