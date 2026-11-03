<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

describe('Composer configuration', function (): void {
    it('requires stock Pest in every Composer project and turns on no test impact analysis', function (): void {
        $root = base_path('../..');
        foreach (['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'] as $project) {
            /** @var array{require?: array<string, string>, require-dev?: array<string, string>} $composer */
            $composer = json_decode((string) file_get_contents("{$root}/{$project}/composer.json"), true, flags: JSON_THROW_ON_ERROR);
            /** @var array{packages: list<array{name: string}>, packages-dev: list<array{name: string}>} $lock */
            $lock = json_decode((string) file_get_contents("{$root}/{$project}/composer.lock"), true, flags: JSON_THROW_ON_ERROR);
            $locked = array_column([...$lock['packages'], ...$lock['packages-dev']], 'name');

            expect($composer['require-dev']['pestphp/pest'] ?? null)->toBe('^5.3', $project)
                ->and([...array_keys($composer['require'] ?? []), ...array_keys($composer['require-dev'] ?? [])])->not->toContain('nckrtl/pestphp-monorepo')
                ->and($locked)->toContain('pestphp/pest')
                ->and($locked)->not->toContain('nckrtl/pestphp-monorepo');
            // Stock Pest also turns TIA on from the PEST_TIA environment variable, so no project configuration sets it.
            foreach (glob("{$root}/{$project}/phpunit*.xml*") ?: [] as $configuration) {
                expect((string) file_get_contents($configuration))->not->toContain('PEST_TIA');
            }
        }
        foreach ([...(glob("{$root}/.github/workflows/*.yml") ?: []), ...(glob("{$root}/.github/actions/*/action.yml") ?: []), ...(glob("{$root}/bin/*") ?: [])] as $file) {
            if (is_file($file)) {
                expect((string) file_get_contents($file))->not->toContain('PEST_TIA');
            }
        }
    });

    it('runs every repository-owned Pest command as a stock full suite without test impact analysis', function (): void {
        foreach ([
            'bin/test',
            'apps/cli/composer.json',
            'apps/docs/composer.json',
            'apps/gateway/composer.json',
            'apps/e2e/composer.json',
            'packages/php-sdk/composer.json',
        ] as $path) {
            $contents = file_get_contents(base_path('../../'.$path));

            expect($contents)->toBeString();
            // One needle per call: a negated toContain passes when any one of several needles is absent.
            foreach (['--tia', '--locally', 'PEST_TIA', '--filter', '--exclude-filter', '--group', '--exclude-group', '--testsuite', '--exclude-testsuite'] as $option) {
                expect($contents)->not->toContain($option);
            }

            if (str_ends_with($path, 'composer.json')) {
                /** @var array{scripts: array<string, mixed>} $composer */
                $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

                expect($composer['scripts']['test'] ?? null)
                    ->toBeString()
                    ->toContain('vendor/bin/pest', '--parallel', '--colors=never')
                    ->not->toContain('--no-progress');
                expect($composer['scripts'])->not->toHaveKey('test:affected')
                    ->and($composer['scripts'])->not->toHaveKey('test:fresh');
                expect(file_get_contents(base_path('../../'.dirname($path).'/tests/Pest.php')))
                    ->not->toContain('tia()')
                    ->not->toContain('->tia(')
                    ->not->toContain('ORBIT_TIA_DIRECTORY');

                array_walk_recursive($composer['scripts'], function (mixed $command): void {
                    if (! is_string($command) || ! str_contains($command, 'vendor/bin/pest')) {
                        return;
                    }

                    expect(preg_match('/(?:^|\s)tests\//', $command))->toBe(0);
                });
            } else {
                expect($contents)->not->toMatch('/vendor\/bin\/pest[^\n]*tests\//');

                if ($path === 'bin/test') {
                    expect($contents)
                        ->toContain('--colors=never')
                        ->not->toContain('--no-progress');
                }

                expect($contents)->not->toContain('tests/');
            }
        }

        foreach ([
            'apps/cli/phpunit.guidance.xml' => ['tests/Feature/BoostGuidanceTest.php'],
            'apps/docs/phpunit.guidance.xml' => ['tests/Unit/RepositoryGuidanceTest.php'],
            'apps/gateway/phpunit.guidance.xml' => [
                'tests/Feature/Configuration/BoostGuidanceTest.php',
                'tests/Unit/Configuration/BoostConfigurationTest.php',
            ],
            'apps/e2e/phpunit.guidance.xml' => [
                'tests/Feature/Configuration/BoostConfigurationTest.php',
                'tests/Feature/Configuration/BoostGuidanceTest.php',
                'tests/Feature/Configuration/ComposerConfigurationTest.php',
            ],
            'packages/php-sdk/phpunit.guidance.xml' => ['tests/Unit/RepositoryGuidanceTest.php'],
        ] as $configuration => $contracts) {
            expect(file_get_contents(base_path('../../'.$configuration)))
                ->toBeString()
                ->toContain(...$contracts);
        }
    });

    it('strips ANSI from Pest output and keeps the summary and exit code', function (): void {
        $root = base_path('../..');
        $plain = $root.'/bin/pest-plain';

        $passed = new Process([
            $plain,
            'php',
            '-r',
            'echo "\e[1ATests: 1 passed\n";',
        ], $root);
        $passed->mustRun();

        expect($passed->getExitCode())->toBe(0)
            ->and($passed->getOutput())->toBe("Tests: 1 passed\n")
            ->and($passed->getErrorOutput())->toBe('');

        $failed = new Process([
            $plain,
            'php',
            '-r',
            'fwrite(STDERR, "\e[31mfail\e[0m\n"); exit(2);',
        ], $root);
        $failed->run();

        expect($failed->getExitCode())->toBe(2)
            ->and($failed->getOutput())->toBe("fail\n")
            ->and($failed->getErrorOutput())->toBe('');
    });

    it('requires analysis level 6 or higher in every Composer project', function (string $project): void {
        $configuration = file_get_contents(base_path('../../'.$project.'/phpstan.neon'));

        expect(preg_match_all('/^\s*level:\s*(\d+)\s*$/m', $configuration, $matches))->toBe(1);
        expect((int) $matches[1][0])->toBeGreaterThanOrEqual(6);
    })->with(['apps/cli', 'apps/gateway', 'apps/docs', 'apps/e2e', 'packages/php-sdk']);

    it('defines the database-free E2E project', function (): void {
        $composer = json_decode(
            (string) file_get_contents(base_path('composer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($composer['name'])
            ->toBe('nckrtl/orbit-e2e')
            ->and($composer['require'])
            ->toHaveKeys(['php', 'composer/semver', 'laravel/framework'])
            ->and($composer['autoload']['psr-4'])
            ->not->toHaveKey('Database\\')->and(json_encode($composer['scripts'], JSON_THROW_ON_ERROR))
            ->not->toMatch('/migrate|artisan dev|sqlite|routes|database/i');

        expect($composer['scripts']['analyse'])
            ->toBe('vendor/bin/phpstan analyse --no-progress --memory-limit=1G');
        expect($composer['scripts']['lint'])
            ->toBe('@format:check');
        expect($composer['scripts'])
            ->not
            ->toHaveKey('test:live-incus')
            ->and($composer['scripts']['test'])
            ->toBe('../../bin/pest-plain vendor/bin/pest --parallel --processes=$(../../bin/check-processes) --compact --colors=never')
            ->and($composer['scripts']['guidance:check'])
            ->toBe('../../bin/guidance-check');
        expect(array_filter(
            array_keys($composer['scripts']),
            static fn (string $script): bool => str_starts_with($script, 'scenario:') || str_contains($script, 'scenario-'),
        ))->toBe([]);
        expect(glob(base_path('phpunit.scenario-*.xml')))->toBe([])
            ->and(is_dir(base_path('tests/Scenario')))->toBeFalse();
        expect(file_get_contents(base_path('../../bin/test')))
            ->toContain("'--parallel'")
            ->not->toContain('incus-live');
        expect(file_get_contents(base_path('../../.github/workflows/ci.yml')))
            ->not->toContain('incus-live');
        expect(file_get_contents(base_path('../../.github/pull_request_template.md')))->not->toContain('bin/e2e-live');

        foreach (['.env.example', 'config/app.php', 'phpunit.xml', 'tests/Pest.php'] as $file) {
            expect(file_get_contents(base_path($file)))
                ->not
                ->toMatch('/DB_|QUEUE_|RefreshDatabase|APP_URL|Gateway|gateway|sqlite|database/i');
        }

        expect(file_get_contents(base_path('config/e2e.php')))->not->toContain('ORBIT_E2E_PROFILE', "'profile'");

        foreach (['app', 'bootstrap', 'commands', 'state', 'tests', 'tooling'] as $rule) {
            expect(trim((string) file_get_contents(base_path(".ai/rules/{$rule}.md"))))->not->toBeEmpty();
        }

        foreach (['pint.json', 'phpstan.neon'] as $file) {
            expect(file_get_contents(base_path($file)))->not->toMatch('/database|routes/i');
        }
    });

    it('gives every CI job a read-only token and records no test impact data', function (): void {
        $source = (string) file_get_contents(base_path('../../.github/workflows/ci.yml'));
        $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
        // CI publishes no quality caches and runs no test suite, so it records, exports and selects nothing with
        // test impact analysis.
        foreach (['sandbox-tia', 'graph.json', '.orbit-tia', 'ORBIT_TIA_DIRECTORY', '--fresh', '--tia', 'Pest TIA graph', 'bin/ci-tia', 'bin/tia-cache', 'tia-baseline.yml', 'publish-ci-cache'] as $needle) {
            expect($source)->not->toContain($needle);
        }
        expect(is_file(base_path('../../bin/tia-cache')))->toBeFalse();
        expect($workflow['permissions'])->toBe(['contents' => 'read']);
        foreach ($workflow['jobs'] as $name => $job) {
            expect($job['permissions']['contents'] ?? $workflow['permissions']['contents'])->toBe('read', $name);
        }
    });

    it('installs only dependencies without preparing development state', function (bool $optional): void {
        $root = temporaryPath('orbit-bootstrap-dependencies-', 6);
        mkdir($root.'/bin', 0700, true);
        mkdir($root.'/tooling', 0700);
        copy(base_path('../../bin/bootstrap'), $root.'/bin/bootstrap');
        $log = $root.'/installs.log';
        $projects = ['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'];
        foreach ($projects as $project) {
            mkdir($root.'/'.$project, 0700, true);
            file_put_contents($root.'/'.$project.'/.env.example', 'EXAMPLE=1');
        }
        foreach (['apps/web', 'apps/pi-server', 'apps/desktop/src-tauri', 'apps/agent'] as $project) {
            mkdir($root.'/'.$project, 0700, true);
        }
        foreach (['composer', 'bun', 'cargo'] as $tool) {
            file_put_contents($root.'/tooling/'.$tool, <<<'SH'
#!/usr/bin/env bash
printf '%s:%s:%s\n' "${PWD#"$FIXTURE_ROOT/"}" "${0##*/}" "$*" >> "$INSTALL_LOG"
[[ "${1:-}" == install || "${1:-}" == fetch ]]
SH);
            chmod($root.'/tooling/'.$tool, 0700);
        }
        foreach (['worktree-cache', 'gateway-app-key'] as $helper) {
            file_put_contents($root.'/bin/'.$helper, "#!/usr/bin/env sh\nexit 19\n");
            chmod($root.'/bin/'.$helper, 0700);
        }
        $process = new Process(['bash', $root.'/bin/bootstrap', '--dependencies-only', ...($optional ? ['--with-bun', '--with-rust'] : [])], $root, [
            'PATH' => $root.'/tooling:'.getenv('PATH'),
            'FIXTURE_ROOT' => $root,
            'INSTALL_LOG' => $log,
            'ORBIT_HOME' => $root.'/home',
        ]);
        $process->mustRun();
        $expected = array_map(static fn (string $project): string => $project.':composer:install --no-interaction --prefer-dist', $projects);
        if ($optional) {
            $expected = [...$expected,
                'apps/web:bun:install --frozen-lockfile',
                'apps/pi-server:bun:install --frozen-lockfile',
                'apps/desktop:bun:install --frozen-lockfile',
                'apps/agent:cargo:fetch --locked',
                'apps/desktop/src-tauri:cargo:fetch --locked',
            ];
        }

        expect(file($log, FILE_IGNORE_NEW_LINES))->toBe($expected);
        foreach ($projects as $project) {
            expect(file_get_contents($root.'/'.$project.'/.env'))->toBe('EXAMPLE=1');
        }
        expect(file_exists($root.'/home/gateway.app-key'))->toBeFalse();
    })->with(['Composer only' => false, 'Bun and Rust too' => true]);

    it('executes the guidance contract when a guidance input is corrupt', function (): void {
        $source = base_path('../../apps/docs');
        // composer guidance:check runs ../../bin/guidance-check, so the copy keeps the monorepo layout.
        $root = temporaryPath('orbit-guidance-check-', 6);
        $project = $root.'/apps/docs';
        mkdir($root.'/bin', 0o700, true);
        mkdir($root.'/tools', 0o700);
        foreach (['guidance-check', 'pest-plain'] as $launcher) {
            copy(base_path('../../bin/'.$launcher), $root.'/bin/'.$launcher);
            chmod($root.'/bin/'.$launcher, 0o700);
        }
        copy(base_path('../../tools/test_runtime.py'), $root.'/tools/test_runtime.py');
        $policy = 'apps/gateway/tests/Support/test-private-tmpdir.regex';
        mkdir(dirname($root.'/'.$policy), 0o700, true);
        copy(base_path('../../'.$policy), $root.'/'.$policy);

        mkdir($project.'/app', 0o700, true);
        mkdir($project.'/tests/Unit', 0o700, true);
        symlink($source.'/vendor', $project.'/vendor');

        foreach ([
            'composer.json',
            'phpunit.guidance.xml',
            'tests/Pest.php',
            'tests/Unit/RepositoryGuidanceTest.php',
        ] as $path) {
            $destination = $project.'/'.$path;
            $directory = dirname($destination);

            if (! is_dir($directory)) {
                mkdir($directory, 0o700, true);
            }

            copy($source.'/'.$path, $destination);
        }

        $guidance = (string) file_get_contents($source.'/AGENTS.md');
        expect($guidance)->toContain('repository-root `docs/`');
        file_put_contents(
            $project.'/AGENTS.md',
            str_replace('repository-root `docs/`', 'corrupt guidance contract', $guidance),
        );

        $process = new Process(['composer', 'guidance:check'], $project);
        $process->setTimeout(30);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();

        expect($process->getExitCode())
            ->not->toBe(0)
            ->and($output)
            ->toContain('repository-root `docs/`');
    });
});

it('gives each main commit its own run and keeps only the newest run of a pull request or handoff branch', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));

    // Each main commit has its own group, so an earlier main run never queues or cancels a newer release candidate.
    // A pull request's merge ref and a handoff branch each share one group and keep only their newest run.
    expect($workflow['concurrency'])->toBe([
        'group' => "ci-\${{ github.workflow }}-\${{ github.ref == 'refs/heads/main' && github.sha || github.ref }}",
        'cancel-in-progress' => "\${{ github.ref != 'refs/heads/main' }}",
    ]);
    // A task-*-handoff push is a handoff commit; a newer one on the same branch cancels it, and never a main or pull request run.
    expect($workflow['on']['push']['branches'])->toBe(['main', 'task-*-handoff']);
});

it('checks out the immutable event commit in every job including reruns', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));

    foreach ($workflow['jobs'] as $name => $job) {
        foreach ($job['steps'] ?? [] as $step) {
            if (str_starts_with($step['uses'] ?? '', 'actions/checkout@')) {
                expect($step['with']['ref'] ?? null)->toBe('${{ github.sha }}', $name);
            }
            expect($step['run'] ?? '')->not->toContain('git reset --hard', 'Re-run all jobs');
        }
    }
});

it('publishes the web build of each main push for the Gateway to install', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $steps = array_column($workflow['jobs']['web']['steps'], null, 'name');
    $names = array_keys($steps);

    expect($steps['Publish web build']['uses'])->toStartWith('actions/upload-artifact@');
    expect($steps['Publish web build'])->toMatchArray([
        'name' => 'Publish web build',
        'if' => "steps.scope.outputs.selected == 'true' && github.ref == 'refs/heads/main' && github.event_name != 'pull_request'",
        'with' => [
            'name' => 'web-dist-${{ github.sha }}',
            'path' => 'apps/web/dist',
            'include-hidden-files' => true,
            'if-no-files-found' => 'error',
            'retention-days' => 14,
        ],
    ]);
    expect(array_search('Publish web build', $names, true))
        ->toBe(array_search('Build', $names, true) + 1);
});

it('sets up PHP 8.5 through the one pinned setup-php action in every PHP job of every workflow', function (): void {
    $action = Yaml::parseFile(base_path('../../.github/actions/php/action.yml'));
    $setup = $action['runs']['steps'][0];
    // `composer` or `php` as a command word, not inside a name such as bin/php-build, which is Python.
    $command = '(?<![\w\/.=-])(?:composer|php)(?![\w.-])';
    // A bin script runs PHP when PHP is its interpreter, when a Bash script calls PHP or Composer, or when a Python
    // script starts an argv list with them. mcp-tools reaches PHP through another script, so it is named.
    $phpBins = ['mcp-tools'];
    foreach (glob(base_path('../../bin/*')) ?: [] as $bin) {
        $lines = is_file($bin) ? file($bin, FILE_IGNORE_NEW_LINES) : false;
        if ($lines === false || $lines === []) {
            continue;
        }
        $body = implode("\n", array_filter(array_slice($lines, 1), static fn (string $line): bool => ! str_starts_with(ltrim($line), '#')));
        // bin/ci-cache-ref runs PHP only on import, in a task workspace; its CI publish step never does.
        if (basename($bin) === 'ci-cache-ref') {
            continue;
        }
        if (str_ends_with($lines[0], 'php')
            || (str_ends_with($lines[0], 'bash') && preg_match("/{$command}/", $body) === 1)
            || (str_ends_with($lines[0], 'python3') && preg_match('/\[\s*[\'"](?:php|composer)[\'"]\s*,/', $body) === 1)) {
            $phpBins[] = basename($bin);
        }
    }
    $runsPhpPattern = "/{$command}|vendor\/bin\/|\bartisan\b|bin\/(?:".implode('|', array_map(static fn (string $bin): string => preg_quote($bin, '/'), $phpBins)).')(?![\w-])/';
    $phpJobs = [];
    $directJobs = [];
    foreach (glob(base_path('../../.github/workflows/*.yml')) ?: [] as $path) {
        $file = basename($path);
        foreach (Yaml::parseFile($path)['jobs'] as $name => $job) {
            $uses = array_column($job['steps'] ?? [], 'uses');
            $runs = implode("\n", array_column($job['steps'] ?? [], 'run'));
            $runsPhp = preg_match($runsPhpPattern, $runs) === 1;
            $direct = collect($job['steps'] ?? [])->first(static fn (array $step): bool => str_starts_with($step['uses'] ?? '', 'shivammathur/setup-php@'));
            if (in_array('./.github/actions/php', $uses, true)) {
                $phpJobs[] = "{$file}:{$name}";
                expect(collect($job['steps'])->firstWhere('uses', './.github/actions/php')['with']['coverage'])->toBe('none');
            } elseif ($direct !== null) {
                // Native Swamp checks need PHP without Composer; effect tests also load database extensions. Both use the shared pin and version.
                $directJobs[] = "{$file}:{$name}";
                expect($direct['uses'])->toBe($setup['uses'])
                    ->and($direct['with']['php-version'])->toBe('8.5');
            } else {
                expect($runsPhp)->toBeFalse("{$file} job {$name} runs PHP without setting it up");
            }
        }
    }

    expect($phpBins)->toContain('orbit-build-cli-binary', 'dependency-audit', 'docs-nav', 'e2e-topology', 'docs-openapi')
        ->not->toContain('php-build');
    expect($phpJobs)->toBe([
        'ci.yml:php', 'ci.yml:gateway-static', 'ci.yml:docs', 'ci.yml:project-check', 'ci.yml:api-reference', 'ci.yml:web',
        'dependency-audit.yml:audit', 'docs-site.yml:publish', 'orbit-cli-binary.yml:build',
    ])
        ->and($directJobs)->toBe(['swamp-shape.yml:validate', 'swamp-shape.yml:native-tests', 'swamp-shape.yml:test-shards']);
    // The shared action installs PHP and Composer only; it compiles no agent and installs no recipe PHP build.
    $actionRuns = implode("\n", array_column($action['runs']['steps'], 'run'));
    expect($setup['uses'])->toMatch('/^shivammathur\/setup-php@[0-9a-f]{40}$/')
        ->and($setup['with'])->toMatchArray([
            'php-version' => '8.5',
            'coverage' => '${{ inputs.coverage }}',
            'tools' => 'composer:2.10.3',
        ])
        // One needle per call: a negated toContain passes when any one of several needles is absent.
        ->and($actionRuns)->not->toContain('cargo')
        ->and($actionRuns)->not->toContain('bin/php-build');
});

it('runs the bin/php-build unit tests', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/php-build-tests.py'), base_path('../../bin/php-build')]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});

it('runs the CI JavaScript native adapter and launcher smoke tests', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/ci-javascript-tests.py')]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});

it('runs the local builder and documented maintenance regression tests', function (string $fixture): void {
    $process = new Process(['python3', base_path('tests/Fixtures/'.$fixture)]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
})->with(['git-build-watch-tests.py', 'incus-upgrade-runbook-tests.py']);
