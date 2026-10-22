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
            ->toContain('coverage: pcov')
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

    it('bounds Web browser installation and retries downloads through Canonical and Playwright', function (): void {
        $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
        $steps = array_column($workflow['jobs']['web']['steps'], null, 'name');
        $install = $steps['Install the test browsers'];

        expect($install['timeout-minutes'] ?? null)->toBe(10)
            ->and($install['env']['PLAYWRIGHT_DOWNLOAD_CONNECTION_TIMEOUT'] ?? null)->toBe('30000');
        expect($install['run'])->toContain(
            "echo 'https://archive.ubuntu.com/ubuntu/' | sudo tee /etc/apt/apt-mirrors.txt",
            'Acquire::Retries "3";',
            'Acquire::http::Timeout "30";',
            'Acquire::https::Timeout "30";',
            'sudo tee /etc/apt/apt.conf.d/80-download-retries',
            'bunx playwright install --with-deps --only-shell chromium webkit',
        );
    });

    it('publishes only the Pint and PHPStan caches of main as SHA-bound refs after Required checks', function (): void {
        $source = (string) file_get_contents(base_path('../../.github/workflows/ci.yml'));
        $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
        $publisher = $workflow['jobs']['publish-ci-cache'];
        expect($publisher['needs'])->toBe(['required'])
            ->and($publisher['if'])->toBe("\${{ !cancelled() && needs.required.result == 'success' && github.repository == 'hardimpactdev/orbit' && github.ref == 'refs/heads/main' && github.event_name != 'pull_request' }}")
            ->and($publisher['permissions'])->toBe(['actions' => 'read', 'contents' => 'write'])
            ->and($publisher['steps'][0]['with']['fetch-depth'])->toBe(0)
            ->and($publisher['steps'][1]['with']['pattern'])->toBe('ci-cache-*-${{ github.sha }}')
            ->and($publisher['steps'][1]['with']['path'])->toBe('${{ runner.temp }}/ci-cache-exports')
            ->and($publisher['steps'][2]['run'])->toContain('bin/ci-cache-ref publish --commit "$GITHUB_SHA" --exports "$RUNNER_TEMP/ci-cache-exports"');
        $projectSteps = array_column($workflow['jobs']['project']['steps'], null, 'name');
        expect($projectSteps['Export main quality caches']['if'])
            ->toBe("success() && github.ref == 'refs/heads/main' && github.event_name != 'pull_request'")
            ->and($projectSteps['Export main quality caches']['run'])
            ->toContain("'schema': 3", "('pint', 'pint.json', 'vendor/pint.cache')", "('phpstan', 'phpstan.neon', 'vendor/phpstan/cache/resultCache.php')");
        expect($projectSteps['Publish main quality caches']['uses'])->toStartWith('actions/upload-artifact@');
        expect($projectSteps['Publish main quality caches'])->toMatchArray([
            'if' => $projectSteps['Export main quality caches']['if'],
            'with' => [
                'name' => 'ci-cache-${{ strategy.job-index }}-${{ github.sha }}',
                'path' => '${{ runner.temp }}/ci-cache/${{ matrix.project.directory }}',
                'if-no-files-found' => 'error',
                'retention-days' => 14,
            ],
        ]);
        // CI runs stock Pest: it records, exports and selects nothing with test impact analysis.
        foreach (['sandbox-tia', 'graph.json', '.orbit-tia', 'ORBIT_TIA_DIRECTORY', '--fresh', '--tia', 'Pest TIA graph'] as $needle) {
            expect($source)->not->toContain($needle);
        }
        foreach ($workflow['jobs'] as $name => $job) {
            if ($name !== 'publish-ci-cache') {
                expect($job['permissions']['contents'] ?? $workflow['permissions']['contents'])->toBe('read');
            }
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
        foreach (['generated-merge', 'worktree-cache', 'gateway-app-key'] as $helper) {
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

    it('installs E2E dependencies without running other projects checks in the E2E job', function (): void {
        $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
        $project = $workflow['jobs']['project'];
        $steps = array_column($project['steps'], null, 'name');

        expect($steps['Install all project dependencies for E2E integration tests'])
            ->toMatchArray([
                'if' => "matrix.project.directory == 'apps/e2e'",
                'working-directory' => '.',
                'run' => 'bin/bootstrap --dependencies-only',
            ]);
        expect($project['defaults']['run']['working-directory'])->toBe('${{ matrix.project.directory }}');
        // bin/check-processes sizes the quality tools from the runner's own CPU and memory limits.
        expect($steps['Run project quality checks'])->toBe(['name' => 'Run project quality checks', 'run' => 'composer check']);
        // Every non-Gateway Composer suite runs in full on every event; the Gateway shards run the Gateway suite.
        expect($steps['Run full test suite'])
            ->toMatchArray([
                'if' => "matrix.project.directory != 'apps/gateway'",
                'run' => '../../bin/pest-plain vendor/bin/pest --parallel --default-time-limit=5 --compact',
            ]);
        expect($workflow['on']['schedule'])->toBe([['cron' => '17 3 * * *']])
            ->and($workflow['permissions'])->toBe(['contents' => 'read']);
        expect($steps)->not->toHaveKeys([
            'Choose the main test selection', 'Run tests affected since the main graph',
            'Run architecture tests', 'Choose whether to run subprocess tests', 'Run subprocess tests',
        ]);
        // The Gateway quality checks share the orbit-shard pool with the shards on every event; no run reserves Sabre.
        expect($project['runs-on'])->toBe(
            "\${{ matrix.project.directory == 'apps/gateway' && vars.ORBIT_SABRE_RUNNER == 'true' && (github.event_name != 'pull_request' || github.event.pull_request.head.repo.full_name == github.repository) && fromJSON('[\"self-hosted\", \"orbit-shard\"]') || 'ubuntu-26.04' }}",
        );
    });

    it('tests pull requests on the merge ref with stock Pest', function (): void {
        $workflow = file_get_contents(base_path('../../.github/workflows/ci.yml'));

        expect($workflow)
            ->toBeString()
            ->toContain('fetch-depth: 0')
            ->toContain('ref: ${{ github.sha }}')
            ->toContain('repository: ${{ github.repository }}')
            ->toContain('actions/cache/restore@')
            ->toContain('actions/cache/save@')
            ->toContain('coverage: pcov')
            ->toContain("github.event_name == 'pull_request'")
            ->not->toContain('bin/ci-tia')
            ->not->toContain('bin/tia-cache')
            ->not->toContain('tia-baseline.yml');

        foreach (['Compute Pest TIA cache prefix', 'Restore Pest TIA graph', 'Save Pest TIA graph', 'orbit-tia-php'] as $deadCache) {
            expect($workflow)->not->toContain($deadCache);
        }
        expect(is_file(base_path('../../bin/tia-cache')))->toBeFalse();
    });

    it('persists per-project Pint caches without reusing Rector caches around the quality checks', function (): void {
        $workflow = file_get_contents(base_path('../../.github/workflows/ci.yml'));

        expect($workflow)
            ->toBeString()
            ->toContain('${{ matrix.project.directory }}/vendor/pint.cache')
            ->not->toContain('${{ matrix.project.directory }}/vendor/rector/cache')
            ->toContain("format('{0}/pint.json', matrix.project.directory)")
            ->toContain("format('{0}/rector.php', matrix.project.directory)")
            ->toContain('orbit-lint-php8.5-${{ matrix.project.directory }}-')
            ->toContain("\${{ env.CHANGE_RUN != 'true' && format('{0}-{1}-', steps.orbit-lint-key.outputs.prefix, github.ref_name) || '' }}")
            ->toContain('${{ steps.orbit-lint-key.outputs.prefix }}-main-')
            ->toContain("\${{ env.CHANGE_RUN != 'true' && format('orbit-lint-php8.5-{0}-', matrix.project.directory) || '' }}");

        expect(strpos($workflow, 'Install dependencies'))
            ->toBeLessThan(strpos($workflow, 'Restore Pint cache'));
        expect(strpos($workflow, 'Restore Pint cache'))
            ->toBeLessThan(strpos($workflow, 'Run project quality checks'));
        expect(strpos($workflow, 'Run project quality checks'))
            ->toBeLessThan(strpos($workflow, 'Save Pint cache'));
    });

    it('persists per-project portable PHPStan result caches after install', function (): void {
        $workflow = file_get_contents(base_path('../../.github/workflows/ci.yml'));

        expect($workflow)
            ->toBeString()
            ->toContain('path: ${{ matrix.project.directory }}/vendor/phpstan/cache/resultCache.php')
            ->toContain("format('{0}/composer.lock', matrix.project.directory)")
            ->toContain("format('{0}/phpstan.neon', matrix.project.directory)")
            ->toContain('orbit-phpstan-php8.5-${{ matrix.project.directory }}-')
            ->toContain("\${{ env.CHANGE_RUN != 'true' && format('{0}-{1}-', steps.orbit-phpstan-key.outputs.prefix, github.ref_name) || '' }}")
            ->toContain('${{ steps.orbit-phpstan-key.outputs.prefix }}-main-')
            ->toContain("\${{ env.CHANGE_RUN != 'true' && format('orbit-phpstan-php8.5-{0}-', matrix.project.directory) || '' }}")
            ->not->toMatch('/path:\s*\$\{\{ matrix\.project\.directory \}\}\/vendor\/phpstan\/cache\s*$/m')
            ->not->toMatch('/path:\s*\$\{\{ matrix\.project\.directory \}\}\/vendor\s*$/m');

        expect(strpos($workflow, 'Install dependencies'))
            ->toBeLessThan(strpos($workflow, 'Restore PHPStan result cache'));
        expect(strpos($workflow, 'Restore PHPStan result cache'))
            ->toBeLessThan(strpos($workflow, 'Run project quality checks'));
        expect(strpos($workflow, 'Run project quality checks'))
            ->toBeLessThan(strpos($workflow, 'Save PHPStan result cache'));
        expect(strpos($workflow, 'Save PHPStan result cache'))
            ->toBeLessThan(strpos($workflow, 'name: Run full test suite'));
    });

    it('saves the project caches only from runs outside change runs', function (): void {
        $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
        $steps = array_column($workflow['jobs']['project']['steps'], null, 'name');

        // actions/cache tries every restore key in a pull request's own scope before main's, so a saved pull request
        // cache would shadow the main cache on the next run of that pull request.
        foreach (['Save PHPStan result cache', 'Save Pint cache'] as $name) {
            expect($steps[$name]['if'])->toBe("success() && env.CHANGE_RUN != 'true'");
        }
    });

    it('requires the full Gateway suite in shards on every event', function (): void {
        $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
        $jobs = $workflow['jobs'];
        $shardCount = count($jobs['gateway-shards']['strategy']['matrix']['shard']);
        $shardSteps = array_column($jobs['gateway-shards']['steps'], null, 'name');
        $projectSteps = array_column($jobs['project']['steps'], null, 'name');

        expect($shardCount)->toBeGreaterThan(1);
        expect($jobs)->not->toHaveKey('gateway-plan');
        expect($jobs['project']['needs'])->toBe('changes');
        expect($jobs['gateway-shards'])->toMatchArray(['needs' => 'changes', 'if' => 'fromJSON(needs.changes.outputs.jobs).gateway-shards'])
            ->toMatchArray([
                'strategy' => ['fail-fast' => false, 'matrix' => ['shard' => range(1, $shardCount)]],
            ]);
        expect($jobs['gateway-shards']['runs-on'])
            ->toBe('${{ vars.ORBIT_SABRE_RUNNER == \'true\' && (github.event_name != \'pull_request\' || github.event.pull_request.head.repo.full_name == github.repository) && fromJSON(\'["self-hosted", "orbit-shard"]\') || \'ubuntu-26.04\' }}');
        expect($shardSteps['Run Gateway shard ${{ matrix.shard }}']['run'])
            ->toBe('../../bin/pest-plain vendor/bin/pest --parallel --default-time-limit=5 --exclude-group=privileged --shard=${{ matrix.shard }}/${{ strategy.job-total }} --compact');
        expect($projectSteps['Verify Gateway shard coverage'])->toMatchArray([
            'if' => "matrix.project.directory == 'apps/gateway'",
            'run' => '../../bin/check-pest-shards',
        ]);
        expect($shardSteps['Keep exception arguments in traces']['run'])
            ->toContain('zend.exception_ignore_args=0', 'PHP_INI_SCAN_DIR=:$RUNNER_TEMP/php');
        foreach ($jobs as $job) {
            foreach ($job['steps'] ?? [] as $step) {
                if (str_starts_with($step['uses'] ?? '', 'actions/cache/')) {
                    expect($step['with']['path'])->not->toContain('tests/.pest', 'shards.json');
                }
                expect($step['run'] ?? '')->not->toContain('--update-shards');
            }
        }

        // Required checks refuses failed, cancelled or skipped full-suite shards, whatever the event.
        expect($jobs['required']['needs'])->toContain('gateway-shards')
            ->and($jobs['required']['steps'][0]['env'])->toMatchArray([
                'NEEDS' => '${{ toJSON(needs) }}',
            ]);
        $required = $jobs['required']['steps'][0]['run'];
        $verdict = function (string $result) use ($required, $jobs): bool {
            $selected = array_fill_keys(array_diff($jobs['required']['needs'], ['changes']), true);
            $needs = array_fill_keys(array_keys($selected), ['result' => 'success']);
            $needs['changes'] = ['result' => 'success', 'outputs' => ['jobs' => json_encode($selected, JSON_THROW_ON_ERROR)]];
            $needs['gateway-shards']['result'] = $result;
            $process = new Process(['bash', '-ec', $required], env: ['NEEDS' => json_encode($needs, JSON_THROW_ON_ERROR)]);

            return $process->run() === 0;
        };
        expect($verdict('success'))->toBeTrue();
        foreach (['failure', 'cancelled', 'skipped'] as $result) {
            expect($verdict($result))->toBeFalse();
        }
        expect($jobs['required']['steps'][0]['env'])->not->toHaveKey('CHANGE_RUN')
            ->and($required)->not->toContain('CHANGE_RUN');

    });

    it('keeps one project lane per suite and preserves main cache indexes', function (): void {
        $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
        expect($workflow['jobs']['project']['strategy']['matrix'])->toBe('${{ fromJSON(needs.changes.outputs.matrix) }}');
        $process = new Process(['python3', 'bin/ci-changes', '--change-run', 'false'], base_path('../..'));
        $process->mustRun();
        $matrix = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['matrix'];

        expect(array_keys($matrix))->toBe(['project']);
        expect(array_column($matrix['project'], 'name'))->toBe(['CLI', 'Docs', 'Gateway', 'E2E', 'PHP SDK']);
        expect(json_encode($matrix, JSON_THROW_ON_ERROR))->not->toContain('subprocess');
    });

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

it('requires the lint and direct tests of the Gateway remote programs in CI', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $job = $workflow['jobs']['remote-programs'];
    $commands = array_column($job['steps'], 'run');

    expect(implode("\n", $commands))->toContain('shellcheck', 'ruff==0.17.0', 'bin/remote-programs-check')
        ->and($workflow['jobs']['required']['needs'])->toContain('remote-programs')
        ->and($job['if'])->toBe('fromJSON(needs.changes.outputs.jobs).remote-programs');
});

it('keeps the whole privileged group required in one disposable CI job', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $job = $workflow['jobs']['privileged'];
    expect($job['runs-on'])->toBe('ubuntu-26.04');
    expect($job)->not->toHaveKey('strategy');
    expect($job['if'])->toBe('fromJSON(needs.changes.outputs.jobs).privileged');
    $commands = implode("\n", array_column($job['steps'], 'run'));
    expect($commands)->toContain('vendor/bin/pest --parallel --default-time-limit=5 --group=privileged --fail-on-empty-test-suite --compact --colors=never');
    expect($commands)->toContain('--group=privileged', '--fail-on-empty-test-suite')
        ->not->toContain('--shard=');
    expect($workflow['jobs']['required']['needs'])->toContain('privileged');
    expect($workflow['jobs']['required']['steps'][0]['env']['NEEDS'])->toBe('${{ toJSON(needs) }}');
});

it('gives every direct CI Pest invocation the stock five-second default', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $commands = [];

    foreach ($workflow['jobs'] as $job) {
        foreach ($job['steps'] ?? [] as $step) {
            foreach (explode("\n", $step['run'] ?? '') as $line) {
                if (str_contains($line, 'vendor/bin/pest')) {
                    $commands[] = $line;
                    expect($line)->toContain('--default-time-limit=5');
                }
            }
        }
    }

    expect($commands)->not->toBeEmpty();
});

it('lets stock ParaTest use every available CPU in each CI test run', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));

    foreach ($workflow['jobs'] as $job) {
        foreach ($job['steps'] ?? [] as $step) {
            $command = $step['run'] ?? '';
            if (str_contains($command, 'vendor/bin/pest --parallel')) {
                expect($command)->not->toContain('--processes')
                    ->not->toContain('--max-processes')
                    ->not->toContain('ORBIT_CHECK_PROCESSES');
            }
        }
    }
});

it('gates the agent and desktop jobs with the shared change selection', function (string $job, string $output): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $gated = $workflow['jobs'][$job];

    expect($gated['needs'])->toBe('changes')
        ->and($gated['if'])->toBe('fromJSON(needs.changes.outputs.jobs).'.$output);
    foreach ($gated['steps'] as $step) {
        expect($step['if'] ?? '')->not->toContain('steps.changes');
    }
})->with([['agent', 'agent'], ['desktop', 'desktop']]);

it('defines a handoff push and a change run once, and lets no job choose its runner or tests by them', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $handoff = "github.event_name == 'push' && startsWith(github.ref, 'refs/heads/task-') && endsWith(github.ref, '-handoff')";
    $change = "github.event_name == 'pull_request' || ".$handoff;

    // A manual run or a push to another task-* branch is neither a handoff push nor a change run. Only steps read
    // them, for cache keys and change bases. The changes job selects projects for all jobs.
    expect($workflow['env']['HANDOFF_PUSH'])->toBe('${{ '.$handoff.' }}')
        ->and($workflow['env']['CHANGE_RUN'])->toBe('${{ '.$change.' }}')
        ->and(substr_count((string) file_get_contents(base_path('../../.github/workflows/ci.yml')), 'refs/heads/task-'))->toBe(2);
    foreach ($workflow['jobs'] as $name => $job) {
        $decision = (string) ($job['if'] ?? '').(is_string($job['runs-on']) ? $job['runs-on'] : '');
        // One needle per call: a negated toContain passes when any one of several needles is absent.
        foreach (['refs/heads/task-', 'CHANGE_RUN', 'HANDOFF_PUSH'] as $needle) {
            expect($decision)->not->toContain($needle);
        }
    }
});

it('keeps a running main push, only the newest waiting one, and every full run', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));

    // Main pushes share one group without cancel-in-progress, so GitHub replaces only a waiting main run. A pull
    // request's merge ref and a handoff branch each keep only their newest run. Scheduled and manual full runs keep
    // a group per ref and commit, so neither a push nor a manual run on another branch replaces one.
    expect($workflow['concurrency'])->toBe([
        'group' => "ci-\${{ github.workflow }}-\${{ (github.event_name == 'schedule' || github.event_name == 'workflow_dispatch') && format('{0}-{1}-{2}', github.event_name, github.ref, github.sha) || github.ref }}",
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
        'if' => "github.ref == 'refs/heads/main' && github.event_name != 'pull_request'",
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

it('sets up the recipe PHP build in every PHP job of every workflow and never floats PHP with setup-php', function (): void {
    $action = Yaml::parseFile(base_path('../../.github/actions/php/action.yml'));
    $steps = array_column($action['runs']['steps'], null, 'name');
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
    foreach (glob(base_path('../../.github/workflows/*.yml')) ?: [] as $path) {
        $file = basename($path);
        expect((string) file_get_contents($path))->not->toContain('setup-php');
        foreach (Yaml::parseFile($path)['jobs'] as $name => $job) {
            $uses = array_column($job['steps'] ?? [], 'uses');
            $runs = implode("\n", array_column($job['steps'] ?? [], 'run'));
            $runsPhp = preg_match($runsPhpPattern, $runs) === 1;
            if (in_array('./.github/actions/php', $uses, true)) {
                $phpJobs[] = "{$file}:{$name}";
                $step = collect($job['steps'])->firstWhere('uses', './.github/actions/php');
                // A job without coverage keeps PCOV loaded, so its loaded extensions still match the recipe.
                expect($step['with']['coverage'])->toBeIn(['pcov', 'none'])
                    ->and($step)->not->toHaveKey('if');
            } else {
                expect($runsPhp)->toBeFalse("{$file} job {$name} runs PHP without the recipe PHP build");
            }
        }
    }

    expect($phpBins)->toContain('orbit-build-cli-binary', 'dependency-audit', 'docs-nav', 'e2e-topology', 'docs-openapi')
        ->not->toContain('php-build');
    expect($phpJobs)->toBe([
        'ci.yml:project', 'ci.yml:gateway-shards', 'ci.yml:privileged', 'ci.yml:remote-programs',
        'ci.yml:api-reference', 'dependency-audit.yml:audit', 'orbit-cli-binary.yml:build',
    ])
        ->and($steps['Install the recipe PHP build']['if'])->toBe("runner.environment == 'github-hosted'")
        ->and($steps['Install the recipe PHP build']['run'])->toContain('sudo python3 bin/php-build install --profile=ci-runner --ci --purge-extras --coverage "$COVERAGE"')
        ->and($steps['Restore the PHP packages']['with']['path'])->toBe('/var/cache/orbit/php-packages')
        ->and($steps['Restore the PHP packages']['if'])->toBe("runner.environment == 'github-hosted'")
        ->and($steps['Restore the PHP packages']['with']['key'])->toBe('orbit-php-build-${{ steps.key.outputs.key }}')
        ->and($steps['Verify the recipe PHP build'])->not->toHaveKey('if')
        ->and($steps['Verify the recipe PHP build']['run'])->toBe('python3 bin/php-build verify --profile=ci-runner');
});

it('runs the bin/php-build unit tests', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/php-build-tests.py'), base_path('../../bin/php-build')]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});
