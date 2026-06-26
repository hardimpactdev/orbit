<?php

declare(strict_types=1);

use App\E2E\Support\E2ECurrentCheckout;
use Symfony\Component\Process\Process;

function quality_check_script_source(): string
{
    return (string) file_get_contents(repo_path('bin/quality-check.sh'));
}

function quality_check_script_position(string $script, string $needle): int
{
    $position = strpos($script, $needle);

    if ($position === false) {
        throw new RuntimeException("Expected quality-check script to contain [{$needle}].");
    }

    return $position;
}

/**
 * @return list<string>
 */
function quality_check_script_labels(string $script, string $arrayName): array
{
    $pattern = '/^'.preg_quote($arrayName, '/').'=\\(\\R(?P<body>.*?)^\\)/ms';
    $matches = [];

    if (preg_match($pattern, $script, $matches) !== 1) {
        throw new RuntimeException("Expected quality-check script to define [{$arrayName}].");
    }

    $labelMatches = [];
    preg_match_all('/^    (?P<label>[a-z0-9_]+)$/m', $matches['body'], $labelMatches);

    return $labelMatches['label'];
}

/**
 * @return list<string>
 */
function quality_check_background_labels(string $script): array
{
    $backgroundLabelMatches = [];
    preg_match_all('/^\s*run_bg (?P<label>[a-z0-9_]+) /m', $script, $backgroundLabelMatches);

    return array_values(array_unique($backgroundLabelMatches['label']));
}

/**
 * @param list<string> $frames
 */
function quality_check_progress_chunks_path(array $frames): string
{
    $directory = sys_get_temp_dir().'/orbit-quality-check-progress-'.bin2hex(random_bytes(6));
    mkdir($directory, 0777, true);

    $chunks = collect($frames)
        ->map(fn (string $frame, int $index): string => json_encode([
            'elapsed' => round($index * 0.3, 1),
            'delta' => $index === 0 ? 0.0 : 0.3,
            'bytes' => strlen($frame),
            'text' => $frame,
        ], JSON_THROW_ON_ERROR))
        ->implode(PHP_EOL);

    file_put_contents("{$directory}/chunks.jsonl", $chunks.PHP_EOL);

    return "{$directory}/chunks.jsonl";
}

/**
 * @param array<string, string> $states
 */
function quality_check_progress_frame(array $states, string $footer = 'Working...'): string
{
    $lines = [
        '  ┌  Running quality checks',
    ];

    foreach ([
        'apps/gateway',
        'apps/cli',
        'apps/docs',
        'apps/e2e',
        'apps/reverb',
        'packages/core',
        'packages/sdk',
    ] as $area) {
        $lines[] = '  │';
        $lines[] = sprintf('  ○  %-15s %s', $area, $states[$area]);
    }

    $lines[] = '  │';
    $lines[] = "  └  {$footer}";

    return implode(PHP_EOL, $lines).PHP_EOL;
}

it('keeps ephemeral e2e on the Incus backend separate from default pest tests', function (): void {
    expect(repo_path('bin/e2e'))->not->toBeFile();
});

it('does not expose a standing live smoke test lane', function (): void {
    $composer = json_decode(
        file_get_contents(repo_path('composer.json')) ?: '',
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(repo_path('bin/live-smoke'))
        ->not->toBeFile()->and($composer['scripts'])
        ->not->toHaveKey('test:live');
});

it('keeps composer test:live and bin/live-smoke out of every doc surface agents read', function (): void {
    $files = collect([
        repo_path('AGENTS.md'),
        repo_path('README.md'),
    ]);

    foreach (['.agents/skills', 'docs/superpowers/plans'] as $relative) {
        $absolute = repo_path($relative);

        if (! is_dir($absolute)) {
            continue;
        }

        $files = $files->merge(
            collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $absolute,
                FilesystemIterator::SKIP_DOTS,
            )))
                ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'md')
                ->map(fn (SplFileInfo $file): string => $file->getPathname()),
        );
    }

    $offenders = $files
        ->filter(fn (string $path): bool => is_file($path))
        ->mapWithKeys(fn (string $path): array => [$path => (string) file_get_contents($path)])
        ->filter(
            fn (string $contents): bool => (
                str_contains($contents, 'composer test:live') || str_contains($contents, 'bin/live-smoke')
            ),
        )
        ->keys()
        ->map(fn (string $path): string => str_replace(repo_path().'/', '', $path))
        ->sort()
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('reports command docs lint severities in agent format', function (): void {
    $composer = json_decode(
        file_get_contents(repo_path('composer.json')) ?: '',
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    $docsLintScript = implode("\n", (array) $composer['scripts']['docs-lint']);

    expect($docsLintScript)
        ->toContain('artisan librarian:lint')
        ->toContain('--format=agent')
        ->toContain('--path=domains')
        ->toContain('--path=testing')
        ->toContain('--group=references')
        ->not->toContain('--path=content/')
        ->not->toContain('--strict');
});

it('uses Mago for analysis, linting, and formatting', function (): void {
    $composer = json_decode(
        file_get_contents(repo_path('composer.json')) ?: '',
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    $magoAnalyzeCommands = [
        'bin/orbit-gateway-vendor-bin mago analyze app config database --reporting-format=medium',
        'cd apps/cli && vendor/bin/mago analyze app config --reporting-format=medium',
        'cd apps/docs && vendor/bin/mago analyze app config database --reporting-format=medium',
        'bin/orbit-gateway-vendor-bin mago --workspace ../reverb analyze bootstrap config routes --reporting-format=medium',
        'cd packages/core && vendor/bin/mago analyze src --reporting-format=medium',
        'cd packages/sdk && vendor/bin/mago analyze src --reporting-format=medium',
        'cd apps/e2e && vendor/bin/mago analyze app config database --reporting-format=medium',
    ];
    $magoProjectCommands = [
        'bin/orbit-gateway-vendor-bin mago',
        'cd apps/cli && vendor/bin/mago',
        'cd apps/docs && vendor/bin/mago',
        'bin/orbit-gateway-vendor-bin mago --workspace ../reverb',
        'cd packages/core && vendor/bin/mago',
        'cd packages/sdk && vendor/bin/mago',
        'cd apps/e2e && vendor/bin/mago',
    ];

    expect($composer['scripts'])
        ->toHaveKeys([
            'analyse',
            'format',
            'mago:analyze',
            'mago:lint',
            'mago:format',
            'mago:format:check',
        ])
        ->and(implode("\n", $composer['scripts']['analyse']))
        ->toContain('mago analyze')
        ->not->toContain('phpstan analyse')->and(implode("\n", $composer['scripts']['format']))->toContain(
            'mago format',
        )
        ->not->toContain('pint');

    foreach ($magoAnalyzeCommands as $command) {
        expect(implode("\n", $composer['scripts']['mago:analyze']))->toContain($command);
    }

    foreach ($magoProjectCommands as $command) {
        expect(implode("\n", $composer['scripts']['mago:lint']))
            ->toContain("{$command} lint --reporting-format=medium")
            ->and(implode("\n", $composer['scripts']['mago:format']))
            ->toContain("{$command} format")
            ->and(implode("\n", $composer['scripts']['mago:format:check']))
            ->toContain("{$command} format --check");
    }

    foreach ([
        'apps/gateway',
        'apps/cli',
        'apps/docs',
        'apps/reverb',
        'apps/e2e',
        'packages/core',
        'packages/sdk',
    ] as $projectPath) {
        $manifest = json_decode(
            file_get_contents(repo_path("{$projectPath}/composer.json")) ?: '',
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($manifest['require-dev']['carthage-software/mago'] ?? null)
            ->toBe('^1.40.1')
            ->and($manifest['require-dev'] ?? [])
            ->not->toHaveKeys([
                'larastan/larastan',
                'laravel/pint',
                'phpstan/phpstan',
            ])->and(repo_path("{$projectPath}/mago.toml"))->toBeFile()->and(repo_path(
                "{$projectPath}/mago-analyzer-baseline.toml",
            ))->toBeFile()->and(repo_path("{$projectPath}/mago-linter-baseline.toml"))->toBeFile()->and(repo_path(
                "{$projectPath}/pint.json",
            ))
            ->not->toBeFile()->and(repo_path("{$projectPath}/phpstan.neon"))
            ->not->toBeFile()->and($manifest['scripts'])->toHaveKeys([
                'analyse',
                'format',
                'mago:analyze',
                'mago:lint',
                'mago:format',
                'mago:format:check',
            ]);
    }
});

it('keeps the aggregate quality gate composer scripts quiet', function (): void {
    $composer = json_decode(
        file_get_contents(repo_path('composer.json')) ?: '',
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    // The gate keeps every subgate while capping background fan-out to the host.
    expect($composer['scripts']['quality-check'])
        ->toBe([
            'bin/quality-check.sh',
        ])
        ->and($composer['scripts']['quality-check:fix'])
        ->toBe([
            'bin/quality-check.sh --fix',
        ]);
});

it('caps aggregate quality gate fan-out by host size', function (): void {
    $script = quality_check_script_source();

    expect($script)
        ->toContain('ORBIT_QUALITY_CHECK_MAX_BACKGROUND_JOBS')
        ->toContain('quality_check_default_max_background_jobs')
        ->toContain('if [ "$detected_jobs" -le 1 ]; then')
        ->toContain('if [ "$detected_jobs" -le 4 ]; then')
        ->toContain('echo 1')
        ->toContain('echo 2')
        ->toContain('default_jobs=$((detected_jobs / 2))')
        ->toContain('echo 8')
        ->toContain('wait_for_bg_slot')
        ->toContain('PROGRESS_TICKER_PID')
        ->toContain('[ "$pid" = "$PROGRESS_TICKER_PID" ]');
});

it('keeps the aggregate quality gate static subgates complete', function (): void {
    $script = quality_check_script_source();

    expect($script)
        ->toContain('librarian:lint')
        ->toContain('APP_CHECK_LABELS=(')
        ->toContain('PACKAGE_BACKGROUND_CHECK_LABELS=(')
        ->toContain('BACKGROUND_CHECK_LABELS=(')
        ->toContain('--path=testing')
        ->toContain('--group=references')
        ->toContain('mago analyze')
        ->toContain('mago lint')
        ->toContain('mago format')
        ->not->toContain('phpstan analyse')
        ->not->toContain('vendor/bin/pint')->toContain('rector process')->toContain(
            'cd apps/cli && vendor/bin/mago analyze',
        )->toContain('cd apps/docs && vendor/bin/mago analyze')->toContain(
            'bin/orbit-gateway-vendor-bin mago --workspace ../reverb analyze',
        )->toContain('cd packages/core && vendor/bin/mago analyze')->toContain(
            'cd packages/sdk && vendor/bin/mago analyze',
        )->toContain('cd apps/e2e && vendor/bin/mago analyze')->toContain(
            'cd apps/cli && vendor/bin/rector process',
        )->toContain('cd apps/docs && vendor/bin/rector process')->toContain(
            'cd packages/core && vendor/bin/rector process',
        )->toContain('cd packages/sdk && vendor/bin/rector process')->toContain(
            'cd apps/e2e && vendor/bin/rector process',
        )->toContain('cd apps/cli && vendor/bin/mago lint')->toContain(
            'cd apps/docs && vendor/bin/mago lint',
        )->toContain('bin/orbit-gateway-vendor-bin mago --workspace ../reverb lint')->toContain(
            'cd packages/core && vendor/bin/mago lint',
        )->toContain('cd packages/sdk && vendor/bin/mago lint')->toContain(
            'cd apps/e2e && vendor/bin/mago lint',
        )->toContain('cd apps/cli && vendor/bin/mago format')->toContain(
            'cd apps/docs && vendor/bin/mago format',
        )->toContain('bin/orbit-gateway-vendor-bin mago --workspace ../reverb format')->toContain(
            'cd packages/core && vendor/bin/mago format',
        )->toContain('cd packages/sdk && vendor/bin/mago format')->toContain(
            'cd apps/e2e && vendor/bin/mago format',
        );
});

it('keeps the aggregate quality gate Pest lanes complete', function (): void {
    $script = quality_check_script_source();

    expect($script)
        ->toContain('bin/orbit-cli-pest')
        ->toContain('bin/orbit-docs-pest')
        ->toContain('cd packages/core && vendor/bin/pest')
        ->toContain('cd packages/sdk && vendor/bin/pest')
        ->toContain('bin/orbit-gateway-pest')
        ->toContain('--exclude-group=e2e')
        ->toContain('--exclude-group=slow')
        ->toContain('--parallel')
        ->toContain('--compact')
        ->not->toContain('bin/orbit-cli-pest-quality')
        ->not->toContain('cd apps/e2e && vendor/bin/pest')
        ->not->toContain('run_bg e2e_pest')
        ->not->toContain('PRE_E2E_PEST_LABELS=(');
});

it('keeps aggregate quality gate labels complete and ordered', function (): void {
    $script = quality_check_script_source();
    $backgroundLabels = quality_check_background_labels(script: $script);
    $waitedBackgroundLabels = [
        ...quality_check_script_labels(script: $script, arrayName: 'APP_CHECK_LABELS'),
        ...quality_check_script_labels(script: $script, arrayName: 'PACKAGE_BACKGROUND_CHECK_LABELS'),
    ];
    $declaredBackgroundLabels = quality_check_script_labels(script: $script, arrayName: 'BACKGROUND_CHECK_LABELS');
    $aggregationLabels = quality_check_script_labels(script: $script, arrayName: 'CHECK_LABELS');
    $expectedAggregationLabels = [
        ...$backgroundLabels,
        'core_pest',
    ];

    sort($backgroundLabels);
    sort($waitedBackgroundLabels);
    sort($declaredBackgroundLabels);
    sort($aggregationLabels);
    sort($expectedAggregationLabels);

    expect($waitedBackgroundLabels)
        ->toBe($backgroundLabels)
        ->and($waitedBackgroundLabels)
        ->toHaveCount(count(array_unique($waitedBackgroundLabels)))
        ->and($declaredBackgroundLabels)
        ->toBe($backgroundLabels)
        ->and($aggregationLabels)
        ->toBe($expectedAggregationLabels)
        ->and($aggregationLabels)
        ->toHaveCount(count(array_unique($aggregationLabels)))
        ->and(quality_check_script_labels(script: $script, arrayName: 'PACKAGE_BACKGROUND_CHECK_LABELS'))
        ->toBe([
            'core_mago_analyze',
            'sdk_mago_analyze',
            'core_mago_lint',
            'sdk_mago_lint',
            'core_rector',
            'sdk_rector',
            'core_mago_format',
            'sdk_mago_format',
            'sdk_pest',
        ])
        ->and(quality_check_script_position(script: $script, needle: 'run_bg gateway_pest'))
        ->toBeLessThan(quality_check_script_position(script: $script, needle: 'run_bg cli_mago_analyze'))
        ->and(quality_check_script_position(script: $script, needle: 'run_bg cli_pest'))
        ->toBeLessThan(quality_check_script_position(script: $script, needle: 'run_bg docs_lint'))
        ->and(quality_check_script_position(script: $script, needle: 'run_bg reverb_mago_format'))
        ->toBeLessThan(quality_check_script_position(
            script: $script,
            needle: 'wait_for_bg_labels "${APP_CHECK_LABELS[@]}"',
        ))
        ->and(quality_check_script_position(script: $script, needle: 'wait_for_bg_labels "${APP_CHECK_LABELS[@]}"'))
        ->toBeLessThan(quality_check_script_position(script: $script, needle: 'run_bg core_mago_analyze'))
        ->and(quality_check_script_position(script: $script, needle: 'run_bg sdk_pest'))
        ->toBeLessThan(quality_check_script_position(
            script: $script,
            needle: 'wait_for_bg_labels "${PACKAGE_BACKGROUND_CHECK_LABELS[@]}"',
        ))
        ->and(quality_check_script_position(
            script: $script,
            needle: 'wait_for_bg_labels "${PACKAGE_BACKGROUND_CHECK_LABELS[@]}"',
        ))
        ->toBeLessThan(quality_check_script_position(script: $script, needle: 'record_subgate_start core_pest'));
});

it('renders a TTY progress tree for aggregate quality-check areas', function (): void {
    $script = (string) file_get_contents(repo_path('bin/quality-check.sh'));
    $scriptPosition = function (string $needle) use ($script): int {
        $position = strpos($script, $needle);

        if ($position === false) {
            throw new RuntimeException("Expected quality-check script to contain [{$needle}].");
        }

        return $position;
    };

    expect($script)
        ->toContain('PROGRESS_AREAS=(')
        ->toContain('quality_check_progress_enabled')
        ->toContain('[ -t 1 ]')
        ->toContain('if [ -n "${NO_COLOR:-}" ]; then')
        ->toContain('quality_check_label_area')
        ->toContain('quality_check_progress_start_ticker')
        ->toContain('quality_check_progress_stop_ticker')
        ->toContain('quality_check_progress_render_final')
        ->toContain('PROGRESS_AREA_WIDTH=15')
        ->toContain('printf -v padded_area')
        ->toContain('Running quality checks')
        ->toContain('Queued')
        ->toContain('Working...')
        ->toContain('Quality checks passed')
        ->toContain('Quality checks failed')
        ->toContain('apps/gateway')
        ->toContain('apps/cli')
        ->toContain('apps/docs')
        ->toContain('apps/e2e')
        ->toContain('apps/reverb')
        ->toContain('packages/core')
        ->toContain('packages/sdk')
        ->toContain('ORBIT_QUALITY_CHECK_PROGRESS_SELF_TEST')
        ->and($scriptPosition('quality_check_progress_start_ticker'))
        ->toBeLessThan($scriptPosition('run_bg gateway_mago_analyze'))
        ->and($scriptPosition('quality_check_progress_stop_ticker'))
        ->toBeLessThan($scriptPosition('print_log "$label"'))
        ->and($scriptPosition('quality_check_progress_render_final'))
        ->toBeLessThan($scriptPosition('print_log "$label"'));
});

it('keeps admitted aggregate quality-check areas running between owned subgates', function (): void {
    $script = quality_check_script_source();

    expect($script)
        ->toContain('quality_check_label_running')
        ->toContain('ORBIT_QUALITY_CHECK_PROGRESS_STATE_SELF_TEST')
        ->toContain('record_area_admitted')
        ->toContain('quality_check_area_admitted "$area"')
        ->toContain('record_subgate_running core_pest')
        ->toContain('clear_subgate_running core_pest');

    $process = new Process(
        [
            'env',
            'ORBIT_QUALITY_CHECK_PROGRESS_STATE_SELF_TEST=1',
            'bash',
            repo_path('bin/quality-check.sh'),
        ],
        repo_path(),
        null,
        null,
        30,
    );
    $process->mustRun();

    $lines = collect(preg_split('/\R+/', trim($process->getOutput())) ?: [])
        ->filter()
        ->values()
        ->all();

    expect($lines)->toBe([
        'initial=waiting',
        'admitted-completed-subgate=running',
        'active=running',
        'admitted-after-active=running',
        'completed=passed',
        'background-count=1',
    ]);
});

it('checks recorded quality-check PTY frames for monotonic area progress', function (): void {
    $queued = [
        'apps/gateway' => 'Queued',
        'apps/cli' => 'Queued',
        'apps/docs' => 'Queued',
        'apps/e2e' => 'Queued',
        'apps/reverb' => 'Queued',
        'packages/core' => 'Queued',
        'packages/sdk' => 'Queued',
    ];

    $appsRunning = array_merge($queued, [
        'apps/gateway' => 'Running',
        'apps/cli' => 'Running',
        'apps/docs' => 'Running',
        'apps/e2e' => 'Running',
        'apps/reverb' => 'Running',
    ]);

    $appsPassed = array_merge($appsRunning, [
        'apps/gateway' => 'Passed',
        'apps/cli' => 'Passed',
        'apps/docs' => 'Passed',
        'apps/e2e' => 'Passed',
        'apps/reverb' => 'Passed',
    ]);

    $acceptedChunksPath = quality_check_progress_chunks_path([
        quality_check_progress_frame($queued),
        quality_check_progress_frame($appsRunning),
        quality_check_progress_frame(array_merge($appsPassed, [
            'packages/core' => 'Running',
            'packages/sdk' => 'Running',
        ])),
        quality_check_progress_frame(array_merge($appsPassed, [
            'packages/core' => 'Passed',
            'packages/sdk' => 'Passed',
        ]), 'Quality checks passed'),
    ]);

    $accepted = new Process([
        PHP_BINARY,
        repo_path('bin/quality-check-progress-frame-check'),
        $acceptedChunksPath,
    ]);
    $accepted->mustRun();

    expect($accepted->getOutput())
        ->toContain('quality-check progress frames: passed')
        ->toContain('max_running_rows=5')
        ->toContain('apps/gateway: Queued -> Running -> Passed')
        ->toContain('packages/core: Queued -> Running -> Passed');

    $bounceChunksPath = quality_check_progress_chunks_path([
        quality_check_progress_frame($queued),
        quality_check_progress_frame(array_merge($queued, [
            'apps/gateway' => 'Running',
        ])),
        quality_check_progress_frame($queued),
    ]);

    $bounce = new Process([
        PHP_BINARY,
        repo_path('bin/quality-check-progress-frame-check'),
        $bounceChunksPath,
    ]);
    $bounce->run();

    expect($bounce->isSuccessful())
        ->toBeFalse()
        ->and($bounce->getErrorOutput())
        ->toContain('apps/gateway returned to Queued after Running');

    $earlyPackageChunksPath = quality_check_progress_chunks_path([
        quality_check_progress_frame($queued),
        quality_check_progress_frame(array_merge($queued, [
            'apps/gateway' => 'Running',
            'packages/core' => 'Running',
        ])),
    ]);

    $earlyPackage = new Process([
        PHP_BINARY,
        repo_path('bin/quality-check-progress-frame-check'),
        $earlyPackageChunksPath,
    ]);
    $earlyPackage->run();

    expect($earlyPackage->isSuccessful())
        ->toBeFalse()
        ->and($earlyPackage->getErrorOutput())
        ->toContain('packages/core was Running before app areas completed');
});

it('maps every aggregate subgate to a quality-check progress area', function (): void {
    $process = new Process(
        [
            'env',
            'ORBIT_QUALITY_CHECK_PROGRESS_SELF_TEST=1',
            'bash',
            repo_path('bin/quality-check.sh'),
        ],
        repo_path(),
        null,
        null,
        30,
    );
    $process->mustRun();

    $lines = collect(preg_split('/\R+/', trim($process->getOutput())) ?: [])
        ->filter()
        ->values()
        ->all();

    expect($lines)->toBe([
        'apps/gateway=5',
        'apps/cli=5',
        'apps/docs=8',
        'apps/e2e=4',
        'apps/reverb=3',
        'packages/core=5',
        'packages/sdk=5',
        'passed=apps/gateway,apps/cli,apps/docs,apps/e2e,apps/reverb,packages/core,packages/sdk',
        'failed=apps/gateway',
    ]);
});

it('includes monorepo path packages in the gateway image build context', function (): void {
    $dockerfile = (string) file_get_contents(repo_path('docker/orbit-gateway/Dockerfile'));
    $dockerignore = (string) file_get_contents(repo_path('docker/orbit-gateway/Dockerfile.dockerignore'));

    foreach (['core', 'sdk'] as $package) {
        expect($dockerfile)
            ->toContain(
                "COPY packages/{$package}/composer.json packages/{$package}/composer.lock /srv/orbit/packages/{$package}/",
            )
            ->toContain("COPY packages/{$package}/src /srv/orbit/packages/{$package}/src")
            ->and($dockerignore)
            ->toContain("!packages/{$package}/")
            ->toContain("!packages/{$package}/composer.json")
            ->toContain("!packages/{$package}/composer.lock")
            ->toContain("!packages/{$package}/src/")
            ->toContain("!packages/{$package}/src/**");
    }
});

it('keeps default composer tests out of e2e lanes', function (): void {
    $composer = json_decode(
        file_get_contents(repo_path('composer.json')) ?: '',
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    $e2eComposer = json_decode(
        file_get_contents(repo_path('apps/e2e/composer.json')) ?: '',
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    $appLocalE2eInMemoryScript = 'vendor/bin/pest --exclude-group=e2e-binary --exclude-group=e2e-binary-acceptance --exclude-group=e2e-feature --exclude-group=e2e-provision --exclude-group=e2e-topology-contract --compact';
    $defaultTestScripts = implode("\n", $composer['scripts']['test']);
    $slowTestScripts = implode("\n", $composer['scripts']['test:slow']);

    expect($composer['scripts']['test'])
        ->sequence(
            fn ($script) => $script->toBe('Composer\\Config::disableProcessTimeout'),
            fn ($script) => $script->toContain('artisan config:clear'),
            fn ($script) => $script
                ->toContain('pest --exclude-group=e2e')
                ->toContain('--parallel')
                ->toContain('--compact'),
            fn ($script) => $script->toContain('bin/orbit-cli-pest --exclude-group=slow --compact'),
            fn ($script) => $script->toContain('bin/orbit-docs-pest --compact'),
            fn ($script) => $script->toContain('cd packages/core && vendor/bin/pest --compact'),
            fn ($script) => $script->toContain('cd packages/sdk && vendor/bin/pest --compact'),
        )
        ->and($composer['scripts']['test:slow'])
        ->sequence(
            fn ($script) => $script->toBe('Composer\\Config::disableProcessTimeout'),
            fn ($script) => $script->toContain('artisan config:clear'),
            fn ($script) => $script
                ->toContain('pest --exclude-group=e2e')
                ->toContain('--parallel')
                ->toContain('--compact'),
            fn ($script) => $script->toContain('bin/orbit-cli-pest --compact'),
            fn ($script) => $script->toContain('bin/orbit-docs-pest --compact'),
            fn ($script) => $script->toContain('cd packages/core && vendor/bin/pest --compact'),
            fn ($script) => $script->toContain('cd packages/sdk && vendor/bin/pest --compact'),
        )
        ->and($e2eComposer['scripts']['test'])
        ->sequence(
            fn ($script) => $script->toBe('@php artisan config:clear --ansi'),
            fn ($script) => $script->toBe($appLocalE2eInMemoryScript),
        );

    expect($defaultTestScripts)
        ->not->toContain('apps/e2e')
        ->not->toContain('bin/orbit-e2e')
        ->not->toContain('ORBIT_E2E=1')
        ->not->toContain('--group=e2e')->and($slowTestScripts)
        ->not->toContain('apps/e2e')
        ->not->toContain('bin/orbit-e2e')
        ->not->toContain('ORBIT_E2E=1')
        ->not->toContain('--group=e2e');

    $e2eScript = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; bin/quality-gate-run --gate=e2e --command="composer test:e2e" -- bin/orbit-e2e-artisan e2e:test @additional_args';
    $dockerE2eScript = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E_LANES=docker bin/quality-gate-run --gate=e2e-docker --command="composer test:e2e:docker" -- bin/orbit-e2e-artisan e2e:test @additional_args';
    $dockerCanaryE2eScript = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E_LANES=docker bin/quality-gate-run --gate=e2e-docker-canary --command="composer test:e2e:docker:canary" -- bin/orbit-e2e-artisan e2e:test --canary @additional_args';
    $incusE2eScript = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E_LANES=incus bin/quality-gate-run --gate=e2e-incus --command="composer test:e2e:incus" -- bin/orbit-e2e-artisan e2e:test @additional_args';

    expect($composer['scripts']['test:e2e'])
        ->toBe([
            'Composer\\Config::disableProcessTimeout',
            $e2eScript,
        ])
        ->and($composer['scripts']['test:e2e:docker'])
        ->toBe([
            'Composer\\Config::disableProcessTimeout',
            $dockerE2eScript,
        ])
        ->and($composer['scripts']['test:e2e:docker:canary'])
        ->toBe([
            'Composer\\Config::disableProcessTimeout',
            $dockerCanaryE2eScript,
        ])
        ->and($composer['scripts']['test:e2e:incus'])
        ->toBe([
            'Composer\\Config::disableProcessTimeout',
            $incusE2eScript,
        ]);

    expect($composer['scripts']['test:e2e:provision'])
        ->toBe([
            'Composer\\Config::disableProcessTimeout',
            'composer test:e2e:provision:docker',
            'composer test:e2e:provision:incus',
        ])
        ->and($composer['scripts']['test:e2e:provision:docker'])
        ->toBe([
            'Composer\\Config::disableProcessTimeout',
            'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; bin/orbit-e2e-artisan e2e:prepare-docker-hosts --force --rebuild operator_gateway_app-dev_app-prod_agent_websocket @additional_args',
        ])
        ->and($composer['scripts']['test:e2e:provision:incus'])
        ->toBe([
            'Composer\\Config::disableProcessTimeout',
            'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; cd apps/e2e && ORBIT_E2E=1 vendor/bin/pest --group=e2e-provision --fail-on-empty-test-suite @additional_args',
        ])
        ->and($composer['scripts']['test:e2e:next'])
        ->toBe([
            'Composer\\Config::disableProcessTimeout',
            'cd apps/e2e && vendor/bin/pest --compact @additional_args',
        ])
        ->and($composer['scripts'])
        ->not->toHaveKey('test:e2e:provisioning')->and($composer['scripts'])
        ->not->toHaveKey('test:e2e:features')->and($composer['scripts'])
        ->not->toHaveKey('test:e2e:features:docker');
});

it('documents the supported verification lanes', function (): void {
    $testing = implode("\n", [
        file_get_contents(repo_path('apps/docs/content/testing/README.md')),
        file_get_contents(repo_path('apps/docs/content/testing/in-memory/README.md')),
        file_get_contents(repo_path('apps/docs/content/testing/e2e/README.md')),
    ]);

    expect(repo_path('TESTING.md'))->not->toBeFile();

    expect($testing)
        ->toContain('# In-memory tests')
        ->toContain('# E2E testing')
        ->toContain('Retained topology proof for feature behavior')
        ->toContain('Manual prepared E2E backed by Docker or Incus')
        ->toContain('Agents must not run any `composer test:e2e*` command')
        ->toContain('composer test:e2e')
        ->toContain('composer test:e2e:docker')
        ->toContain('composer test:e2e:incus')
        ->toContain('composer test:e2e:provision:docker')
        ->toContain('composer test:e2e:provision:incus')
        ->toContain('composer test:e2e:provision')
        ->toContain('composer test')
        ->not->toContain('composer test:e2e:features')
        ->not->toContain('composer test:live')
        ->not->toContain('bin/live-smoke')
        ->not->toContain('Standing Live Node Rule');
});

it('documents the e2e docker benchmark protocol', function (): void {
    $testing = file_get_contents(repo_path('apps/docs/content/testing/e2e/performance.md'));

    expect($testing)
        ->toContain('## E2E Docker lane - benchmark protocol')
        ->toContain('ORBIT_E2E_TIMINGS=1 \\')
        ->toContain('composer test:e2e:docker:canary \\')
        ->toContain('2>&1 | tee /tmp/e2e-canary.log | awk -f bin/e2e-timings.awk')
        ->toContain('composer test:e2e:docker \\')
        ->toContain('2>&1 | tee /tmp/e2e-full.log | awk -f bin/e2e-timings.awk')
        ->toContain('## Required SSH multiplexing for measured Docker baselines')
        ->toContain('ControlMaster auto')
        ->toContain('ControlPath ~/.ssh/cm-%r@%h:%p.sock')
        ->toContain('ssh -G sidecar1')
        ->toContain('time ssh -o BatchMode=yes sidecar1 true')
        ->toContain('10-20 ms');
});

it('keeps active testing docs on current e2e script names', function (): void {
    $testing = file_get_contents(repo_path('apps/docs/content/testing/e2e/README.md'));

    expect($testing)
        ->toContain('composer test:e2e')
        ->toContain('composer test:e2e:docker')
        ->toContain('composer test:e2e:incus')
        ->toContain('composer test:e2e:provision')
        ->not->toContain('composer test:e2e:features')
        ->not->toContain('composer test:e2e:features:docker');
});

it('keeps reusable e2e support code free of Pest-only expectations', function (): void {
    $supportFiles = collect(new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(repo_path('apps/gateway/app/E2E/Support')),
    ))
        ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php')
        ->mapWithKeys(fn (SplFileInfo $file): array => [
            $file->getPathname() => file_get_contents($file->getPathname()) ?: '',
        ]);

    expect($supportFiles)->each(fn ($contents) => $contents->not->toContain('expect('));
});

it('does not expose hetzner e2e support', function (): void {
    $composer = json_decode(
        file_get_contents(repo_path('composer.json')) ?: '',
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['scripts'])
        ->not->toHaveKey('test:e2e:hcloud-docker')
        ->not->toHaveKey('e2e:reap-hcloud')
        ->not->toHaveKey('e2e:prepare-hcloud-images');

    expect(is_file(repo_path('apps/gateway/app/Console/Commands/E2EReapHcloudCommand.php')))
        ->toBeFalse()
        ->and(is_file(repo_path('apps/gateway/app/Console/Commands/E2ETestHcloudDockerCommand.php')))
        ->toBeFalse()
        ->and(is_file(repo_path('apps/gateway/app/E2E/Support/HcloudProvider.php')))
        ->toBeFalse()
        ->and(is_file(repo_path('apps/gateway/app/E2E/Support/HcloudInstance.php')))
        ->toBeFalse()
        ->and(is_file(repo_path('apps/gateway/app/Services/E2E/HcloudE2EReaper.php')))
        ->toBeFalse()
        ->and(is_file(repo_path('apps/gateway/app/Services/E2E/HcloudDockerE2ERunner.php')))
        ->toBeFalse()
        ->and(is_file(repo_path('apps/gateway/app/Services/E2E/HcloudDockerE2ERunOptions.php')))
        ->toBeFalse();
});

it('exposes e2e preflight, preparation, and cleanup helpers', function (): void {
    $composer = json_decode(
        file_get_contents(repo_path('composer.json')) ?: '',
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    $e2eEnvPrefix = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a;';

    expect($composer['scripts']['e2e:preflight'])
        ->toBe("{$e2eEnvPrefix} bin/orbit-e2e-artisan e2e:preflight @additional_args")
        ->and($composer['scripts']['e2e:prepare-docker-runtime'])
        ->toBe([
            'Composer\\Config::disableProcessTimeout',
            "{$e2eEnvPrefix} bin/orbit-e2e-artisan e2e:prepare-docker-runtime @additional_args",
        ])
        ->and($composer['scripts']['e2e:prepare-docker-topology'])
        ->toBe([
            'Composer\\Config::disableProcessTimeout',
            "{$e2eEnvPrefix} bin/orbit-e2e-artisan e2e:prepare-docker-topology @additional_args",
        ])
        ->and($composer['scripts']['e2e:prepare-docker-hosts'])
        ->toBe([
            'Composer\\Config::disableProcessTimeout',
            "{$e2eEnvPrefix} bin/orbit-e2e-artisan e2e:prepare-docker-hosts @additional_args",
        ])
        ->and($composer['scripts']['e2e:ensure-artifacts'])
        ->toBe([
            'Composer\\Config::disableProcessTimeout',
            "{$e2eEnvPrefix} bin/orbit-e2e-artisan e2e:ensure-artifacts @additional_args",
        ])
        ->and($composer['scripts']['e2e:prepare-base-image'])
        ->toBe([
            'Composer\\Config::disableProcessTimeout',
            "{$e2eEnvPrefix} bin/orbit-e2e-artisan e2e:prepare-base-image @additional_args",
        ])
        ->and($composer['scripts']['e2e:prepare-topology'])
        ->toBe([
            'Composer\\Config::disableProcessTimeout',
            "{$e2eEnvPrefix} bin/orbit-e2e-artisan e2e:prepare-topology @additional_args",
        ])
        ->and($composer['scripts']['e2e:prepare-warm-topology'])
        ->toBe([
            'Composer\\Config::disableProcessTimeout',
            "{$e2eEnvPrefix} bin/orbit-e2e-artisan e2e:prepare-warm-topology @additional_args",
        ])
        ->and($composer['scripts']['e2e:reap-incus'])
        ->toBe("{$e2eEnvPrefix} bin/orbit-e2e-artisan e2e:reap-incus @additional_args")
        ->and($composer['scripts']['e2e:reap-docker'])
        ->toBe("{$e2eEnvPrefix} bin/orbit-e2e-artisan e2e:reap-docker @additional_args");
});

it('keeps reusable e2e harness code out of the Tests namespace for app commands', function (): void {
    $appFiles = collect(new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(repo_path('apps/gateway/app'), FilesystemIterator::SKIP_DOTS),
    ))
        ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php');

    $offenders = $appFiles
        ->filter(fn (SplFileInfo $file): bool => str_contains(
            (string) file_get_contents($file->getPathname()),
            'Tests\\E2E\\Support',
        ))
        ->map(fn (SplFileInfo $file): string => str_replace(repo_path().'/', '', $file->getPathname()))
        ->values()
        ->all();

    expect($offenders)->toBe([]);

    expect(is_file(repo_path('apps/gateway/app/Console/Commands/E2EPrepareHcloudImagesCommand.php')))->toBeFalse();
});

it('registers ephemeral e2e as a guarded Pest group', function (): void {
    $phpunit = file_get_contents(repo_path('apps/gateway/phpunit.xml'));
    $pest = file_get_contents(repo_path('apps/gateway/tests/Pest.php'));

    expect($phpunit)
        ->toContain('<testsuite name="E2E">')
        ->toContain('<directory>tests/E2E</directory>')
        ->and($pest)
        ->toContain('ORBIT_E2E')
        ->toContain("->group('e2e')")
        ->toContain("->in('E2E')");
});

it('keeps persisted orbit certificate material out of the docker build context', function (): void {
    $dockerignore = file_get_contents(repo_path('docker/e2e/topology/Dockerfile.dockerignore'));

    expect($dockerignore)
        ->toContain('apps/gateway/storage/app/orbit/ca/**')
        ->toContain('apps/gateway/storage/app/orbit/certs/**')
        ->toContain('apps/gateway/storage/app/orbit/keys/**');
});

it('keeps local composer dependencies in the docker topology build context', function (): void {
    $dockerignore = file_get_contents(repo_path('docker/e2e/topology/Dockerfile.dockerignore'));
    $ignoredPaths = preg_split('/\R/', trim($dockerignore));

    expect(in_array('vendor', $ignoredPaths, true))->toBeFalse();
});

it('keeps persisted orbit certificate material out of source-less docker topology preparation', function (): void {
    $dockerfile = file_get_contents(repo_path('docker/e2e/topology/Dockerfile'));

    expect($dockerfile)
        ->toContain('LABEL org.orbit.e2e.source="prepared-checkout"')
        ->not->toContain('/opt/orbit-source')
        ->not->toContain('cp -a /opt/orbit-source/. /home/operator/orbit/')
        ->not->toContain('cp -a /opt/orbit-source/. /home/orbit/orbit/');

    expect(E2ECurrentCheckout::archiveExcludePatterns())
        ->toContain('./apps/gateway/storage/app/orbit/ca/*')
        ->toContain('./apps/gateway/storage/app/orbit/certs/*')
        ->toContain('./apps/gateway/storage/app/orbit/keys/*');
});

it('keeps the docker topology host image free of host Composer dependencies', function (): void {
    $dockerfile = file_get_contents(repo_path('docker/e2e/topology/Dockerfile'));

    expect($dockerfile)
        ->not->toContain('COPY --from=composer')
        ->not->toContain('composer install');
});

it('installs Docker via docker.com and downloads the prebuilt CLI binary instead of host PHP', function (): void {
    $script = file_get_contents(repo_path('bin/install-orbit'));

    expect($script)
        ->toContain('download.docker.com')
        ->toContain('docker.gpg')
        ->toContain('docker-ce')
        ->toContain('download_cli_binary')
        ->not->toContain('ppa:ondrej/php')
        ->not->toContain('php8.5-cli')
        ->not->toContain('packages.sury.org/php')
        ->not->toContain('sury-php.gpg')
        ->not->toContain('ppa.launchpadcontent.net')
        ->not->toContain('keyserver.ubuntu.com');
});

it('does not wait for guest initialization tooling before mutating apt on Ubuntu', function (): void {
    $script = file_get_contents(repo_path('bin/install-orbit'));

    expect($script)->not->toContain('cloud-init');
});

it('aggregates e2e timing lines by label and event', function (): void {
    $input = <<<'TEXT'
        [orbit-e2e] topology acquire 1.25s
        [orbit-e2e] topology acquire 2.50s
        [orbit-e2e] topology acquire 3.75s
        [orbit-e2e] topology reset 4.00s
        [orbit-e2e] malformed
        [orbit-e2e] topology acquire nope
        noise line
        [orbit-e2e] node new 9.00s
        [orbit-e2e] checkout checkout operator checkout.copy 0.75s
        TEXT;

    $process = new Process([
        'awk',
        '-f',
        repo_path('bin/e2e-timings.awk'),
    ]);
    $process->setInput($input);
    $process->mustRun();

    $lines = collect(preg_split('/\R+/', trim($process->getOutput())) ?: [])
        ->filter()
        ->sort()
        ->values()
        ->all();

    expect($lines)->toBe([
        'checkout/checkout.operator.checkout.copy n=1 p50=0.75 p95=0.75',
        'node/new n=1 p50=9 p95=9',
        'topology/acquire n=3 p50=2.5 p95=3.75',
        'topology/reset n=1 p50=4 p95=4',
    ]);
});

it('does not install host Supervisor because runtime processes live inside Docker containers', function (): void {
    $script = file_get_contents(repo_path('bin/install-orbit'));

    expect($script)
        ->not
        ->toContain('supervisor')
        ->toContain('orbit-gateway:current');
});

it('installs the SSH client as a operator-node provisioning prerequisite', function (): void {
    $script = file_get_contents(repo_path('bin/install-orbit'));

    expect($script)->toContain('openssh-client');
});

it('does not install host PHP SQLite packages because the CLI binary embeds pdo_sqlite', function (): void {
    $script = file_get_contents(repo_path('bin/install-orbit'));

    expect(preg_match_all('/^\s+sqlite3\s+\\\\$/m', $script))
        ->toBe(0)
        ->and($script)
        ->not->toContain('php8.4-sqlite3')->and($script)
        ->not->toContain('php8.5-sqlite3')->and($script)->toContain('orbit-gateway:current');
});

it('aligns orbit checkout and config root ownership so non-root users can write after container bootstrap', function (): void {
    $script = file_get_contents(repo_path('bin/install-orbit'));

    expect($script)
        ->toContain('finalize_target_ownership')
        ->toContain('finalize_config_root_ownership')
        ->toContain('--no-same-owner')
        ->toContain('sudo_run chown -R "$owner:$group" "$CONFIG_ROOT"')
        ->toContain('chown -R');
});

it('documents e2e topology timing event names', function (): void {
    $testing = file_get_contents(repo_path('apps/docs/content/testing/e2e/performance.md'));

    expect($testing)
        ->toContain('batch.copy-start')
        ->toContain('agent-ready.<role>')
        ->toContain('command-ready.<role>')
        ->toContain('wireguard')
        ->toContain('cleanup.<role>');
});

it('does not expose stale per-topology feature e2e aliases', function (): void {
    $composerJson = file_get_contents(repo_path('composer.json'));

    $composer = json_decode(
        $composerJson === false ? '' : $composerJson,
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['scripts'])
        ->not->toHaveKey('test:e2e:features:operator')
        ->not->toHaveKey('test:e2e:features:operator-gateway')
        ->not->toHaveKey('test:e2e:features:operator-gateway-dev')
        ->not->toHaveKey('test:e2e:features:operator-gateway-dev-prod')
        ->not->toHaveKey('test:e2e:features:parallel')
        ->not->toHaveKey('test:e2e:features:docker:operator-gateway-dev-prod');
});

it('runs the topology contract against the Docker full topology by default', function (): void {
    $composer = json_decode(
        file_get_contents(repo_path('composer.json')) ?: '',
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['scripts']['test:e2e:topology-contract'])
        ->toBe([
            'Composer\\Config::disableProcessTimeout',
            'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; cd apps/e2e && ORBIT_E2E=1 ORBIT_E2E_TOPOLOGY_PROVIDER=docker vendor/bin/pest --group=e2e-topology-contract-operator_gateway_app-dev_app-prod_agent_websocket @additional_args',
        ])
        ->and($composer['scripts'])
        ->not->toHaveKey('test:e2e:topology-contract:operator')
        ->not->toHaveKey('test:e2e:topology-contract:operator-gateway')
        ->not->toHaveKey('test:e2e:topology-contract:operator-gateway-dev')
        ->not->toHaveKey('test:e2e:topology-contract:operator-gateway-dev-prod');
});
