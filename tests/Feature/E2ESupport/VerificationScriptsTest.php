<?php

declare(strict_types=1);

use App\Console\Commands\E2EPreflightCommand;
use App\Console\Commands\E2EPrepareBaseImageCommand;
use App\Console\Commands\E2EPrepareDockerRuntimeCommand;
use App\Console\Commands\E2EPrepareDockerTopologyCommand;
use App\Console\Commands\E2EPrepareIncusImagesCommand;
use App\Console\Commands\E2EPrepareTopologyCommand;
use App\Console\Commands\E2EReapDockerCommand;
use App\Console\Commands\E2EReapHcloudCommand;
use App\Console\Commands\E2EReapIncusCommand;
use App\Console\Commands\E2ETestCommand;
use Symfony\Component\Process\Process;

it('keeps ephemeral e2e on the Incus backend separate from default pest tests', function (): void {
    expect(base_path('bin/e2e'))->not->toBeFile();
});

it('does not expose a standing live smoke test lane', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect(base_path('bin/live-smoke'))->not->toBeFile()
        ->and($composer['scripts'])->not->toHaveKey('test:live');
});

it('keeps composer test:live and bin/live-smoke out of every doc surface agents read', function (): void {
    $files = collect([
        base_path('AGENTS.md'),
        base_path('README.md'),
    ]);

    foreach (['.agents/skills', 'docs/superpowers/plans'] as $relative) {
        $absolute = base_path($relative);

        if (! is_dir($absolute)) {
            continue;
        }

        $files = $files->merge(
            collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)))
                ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'md')
                ->map(fn (SplFileInfo $file): string => $file->getPathname())
        );
    }

    $offenders = $files
        ->filter(fn (string $path): bool => is_file($path))
        ->mapWithKeys(fn (string $path): array => [$path => (string) file_get_contents($path)])
        ->filter(fn (string $contents): bool => str_contains($contents, 'composer test:live') || str_contains($contents, 'bin/live-smoke'))
        ->keys()
        ->map(fn (string $path): string => str_replace(base_path().'/', '', $path))
        ->sort()
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('reports command docs lint severities in agent format', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['docs-lint'])
        ->toContain('artisan librarian:lint')
        ->toContain('--format=agent')
        ->toContain('--path=docs/domains')
        ->not->toContain('--strict');
});

it('keeps the aggregate quality gate complete', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    // The gate fans out docs-lint, phpstan, rector, and pint concurrently while
    // the default Pest suite runs in parallel through `bin/quality-check.sh`.
    expect($composer['scripts']['quality-check'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'bin/quality-check.sh',
    ]);

    $script = (string) file_get_contents(base_path('bin/quality-check.sh'));

    expect($script)
        ->toContain('librarian:lint')
        ->toContain('phpstan analyse')
        ->toContain('rector process')
        ->toContain('vendor/bin/pint')
        ->toContain('vendor/pestphp/pest/bin/pest')
        ->toContain('--exclude-group=e2e')
        ->toContain('--exclude-group=slow')
        ->toContain('--parallel')
        ->toContain('--compact');
});

it('runs default ephemeral e2e through prepared topology lanes', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['test'])
        ->sequence(
            fn ($script) => $script->toBe('Composer\\Config::disableProcessTimeout'),
            fn ($script) => $script->toContain('artisan config:clear'),
            fn ($script) => $script
                ->toContain('pest --exclude-group=e2e')
                ->toContain('--parallel')
                ->toContain('--compact'),
        );

    $e2eScript = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; php artisan e2e:test @additional_args';
    $dockerE2eScript = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E_LANES=docker php artisan e2e:test @additional_args';
    $dockerCanaryE2eScript = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E_LANES=docker php artisan e2e:test --canary @additional_args';
    $incusE2eScript = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E_LANES=incus php artisan e2e:test @additional_args';

    expect($composer['scripts']['test:e2e'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        $e2eScript,
    ])->and($composer['scripts']['test:e2e:docker'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        $dockerE2eScript,
    ])->and($composer['scripts']['test:e2e:docker:canary'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        $dockerCanaryE2eScript,
    ])->and($composer['scripts']['test:e2e:incus'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        $incusE2eScript,
    ]);

    expect($composer['scripts']['test:e2e:provision'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; processes=${ORBIT_E2E_PROVISION_PARALLEL_PROCESSES:-1}; if [ "$processes" -gt 1 ]; then ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e-provision --parallel --processes="$processes" @additional_args; else ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e-provision @additional_args; fi',
    ])->and($composer['scripts'])->not->toHaveKey('test:e2e:provisioning')
        ->and($composer['scripts'])->not->toHaveKey('test:e2e:features')
        ->and($composer['scripts'])->not->toHaveKey('test:e2e:features:docker');
});

it('documents the supported verification lanes', function (): void {
    $testing = file_get_contents(base_path('TESTING.md'));

    expect($testing)
        ->toContain('## In-Memory Pest')
        ->toContain('## Ephemeral E2E')
        ->toContain('Docker-backed feature E2E')
        ->toContain('composer test:e2e')
        ->toContain('composer test:e2e:docker')
        ->toContain('composer test:e2e:incus')
        ->toContain('composer test:e2e:provision')
        ->toContain('composer test')
        ->not->toContain('composer test:e2e:features')
        ->not->toContain('composer test:live')
        ->not->toContain('bin/live-smoke')
        ->not->toContain('Standing Live Node Rule');
});

it('documents the e2e docker benchmark protocol', function (): void {
    $testing = file_get_contents(base_path('TESTING.md'));

    expect($testing)
        ->toContain('## E2E Docker lane - benchmark protocol')
        ->toContain('ORBIT_E2E_TIMINGS=1 ORBIT_E2E_PARALLEL_PROCESSES=8 \\')
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

it('keeps active testing and orchestration docs on current e2e script names', function (): void {
    $testing = file_get_contents(base_path('TESTING.md'));
    $orchestration = file_get_contents(base_path('docs/superpowers/plans/solo-orchestration/README.md'));

    expect($testing)
        ->toContain('composer test:e2e')
        ->toContain('composer test:e2e:docker')
        ->toContain('composer test:e2e:incus')
        ->toContain('composer test:e2e:provision')
        ->not->toContain('composer test:e2e:features')
        ->not->toContain('composer test:e2e:features:docker');

    expect($orchestration)
        ->toContain('composer test:e2e')
        ->toContain('composer test:e2e:provision')
        ->not->toContain('composer test:e2e:features')
        ->not->toContain('composer test:e2e:features:docker');
});

it('keeps reusable e2e support code free of Pest-only expectations', function (): void {
    $supportFiles = collect(new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('app/E2E/Support')),
    ))
        ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php')
        ->mapWithKeys(fn (SplFileInfo $file): array => [
            $file->getPathname() => file_get_contents($file->getPathname()) ?: '',
        ]);

    expect($supportFiles)->each(fn ($contents) => $contents->not->toContain('expect('));
});

it('exposes the hcloud e2e resource reaper', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['e2e:reap-hcloud'])->toBe('set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; php artisan e2e:reap-hcloud @additional_args');
});

it('exposes e2e preflight, preparation, and cleanup helpers', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    $e2eEnvPrefix = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a;';

    expect($composer['scripts']['e2e:preflight'])->toBe("{$e2eEnvPrefix} php artisan e2e:preflight @additional_args")
        ->and($composer['scripts']['e2e:prepare-docker-runtime'])->toBe([
            'Composer\\Config::disableProcessTimeout',
            "{$e2eEnvPrefix} php artisan e2e:prepare-docker-runtime @additional_args",
        ])->and($composer['scripts']['e2e:prepare-docker-topology'])->toBe([
            'Composer\\Config::disableProcessTimeout',
            "{$e2eEnvPrefix} php artisan e2e:prepare-docker-topology @additional_args",
        ])->and($composer['scripts']['e2e:prepare-docker-hosts'])->toBe([
            'Composer\\Config::disableProcessTimeout',
            "{$e2eEnvPrefix} php artisan e2e:prepare-docker-hosts @additional_args",
        ])->and($composer['scripts']['e2e:prepare-base-image'])->toBe([
            'Composer\\Config::disableProcessTimeout',
            "{$e2eEnvPrefix} php artisan e2e:prepare-base-image @additional_args",
        ])->and($composer['scripts']['e2e:prepare-topology'])->toBe([
            'Composer\\Config::disableProcessTimeout',
            "{$e2eEnvPrefix} php artisan e2e:prepare-topology @additional_args",
        ])->and($composer['scripts']['e2e:reap-incus'])->toBe("{$e2eEnvPrefix} php artisan e2e:reap-incus @additional_args")
        ->and($composer['scripts']['e2e:reap-docker'])->toBe("{$e2eEnvPrefix} php artisan e2e:reap-docker @additional_args")
        ->and($composer['scripts'])->not->toHaveKey('e2e:prepare-hcloud-images');
});

it('keeps reusable e2e harness code out of the Tests namespace for app commands', function (): void {
    $appFiles = collect(new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS),
    ))
        ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php');

    $offenders = $appFiles
        ->filter(fn (SplFileInfo $file): bool => str_contains((string) file_get_contents($file->getPathname()), 'Tests\\E2E\\Support'))
        ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
        ->values()
        ->all();

    expect($offenders)->toBe([]);

    expect(is_file(base_path('app/Console/Commands/E2EPrepareHcloudImagesCommand.php')))->toBeFalse();
});

it('registers ephemeral e2e as a guarded Pest group', function (): void {
    $phpunit = file_get_contents(base_path('phpunit.xml'));
    $pest = file_get_contents(base_path('tests/Pest.php'));

    expect($phpunit)
        ->toContain('<testsuite name="E2E">')
        ->toContain('<directory>tests/E2E</directory>')
        ->and($pest)
        ->toContain('ORBIT_E2E')
        ->toContain("->group('e2e')")
        ->toContain("->in('E2E')");
});

it('keeps persisted orbit certificate material out of the docker build context', function (): void {
    $dockerignore = file_get_contents(base_path('docker/e2e/topology/Dockerfile.dockerignore'));

    expect($dockerignore)
        ->toContain('storage/app/orbit/ca/**')
        ->toContain('storage/app/orbit/certs/**')
        ->toContain('storage/app/orbit/keys/**');
});

it('keeps local composer dependencies in the docker topology build context', function (): void {
    $dockerignore = file_get_contents(base_path('docker/e2e/topology/Dockerfile.dockerignore'));
    $ignoredPaths = preg_split('/\R/', trim($dockerignore));

    expect(in_array('vendor', $ignoredPaths, true))->toBeFalse();
});

it('removes persisted orbit certificate material from runtime image worktrees before install', function (): void {
    $dockerfile = file_get_contents(base_path('docker/e2e/topology/Dockerfile'));

    expect($dockerfile)
        ->toContain('cp -a /opt/orbit-source/. /home/control/orbit/')
        ->toContain('cp -a /opt/orbit-source/. /home/orbit/orbit/')
        ->toContain('rm -rf /opt/orbit-source/storage/app/orbit/ca /opt/orbit-source/storage/app/orbit/certs /opt/orbit-source/storage/app/orbit/keys')
        ->toContain('rm -rf /home/control/orbit/storage/app/orbit/ca /home/control/orbit/storage/app/orbit/certs /home/control/orbit/storage/app/orbit/keys')
        ->toContain('rm -rf /home/orbit/orbit/storage/app/orbit/ca /home/orbit/orbit/storage/app/orbit/certs /home/orbit/orbit/storage/app/orbit/keys');
});

it('keeps the docker topology host image free of host Composer dependencies', function (): void {
    $dockerfile = file_get_contents(base_path('docker/e2e/topology/Dockerfile'));

    expect($dockerfile)
        ->not->toContain('COPY --from=composer')
        ->not->toContain('composer install');
});

it('registers the e2e artisan commands', function (): void {
    $commands = [
        E2EPreflightCommand::class,
        E2EPrepareIncusImagesCommand::class,
        E2EPrepareBaseImageCommand::class,
        E2EPrepareTopologyCommand::class,
        E2EPrepareDockerRuntimeCommand::class,
        E2EPrepareDockerTopologyCommand::class,
        E2EReapDockerCommand::class,
        E2EReapIncusCommand::class,
        E2EReapHcloudCommand::class,
        E2ETestCommand::class,
    ];

    foreach ($commands as $class) {
        expect(class_exists($class))->toBeTrue("{$class} does not exist");

        $command = app($class);
        expect($command->isHidden())->toBeTrue("{$class} should be hidden");
    }

    $jsonCommands = [
        E2EPreflightCommand::class,
        E2EPrepareIncusImagesCommand::class,
        E2EPrepareBaseImageCommand::class,
        E2EPrepareTopologyCommand::class,
        E2EPrepareDockerTopologyCommand::class,
        E2EReapDockerCommand::class,
        E2EReapIncusCommand::class,
        E2EReapHcloudCommand::class,
    ];

    foreach ($jsonCommands as $class) {
        $command = app($class);
        expect($command->getDefinition()->hasOption('json'))->toBeTrue("{$class} should accept --json");
    }
});

it('installs Docker via docker.com and host PHP through the Ubuntu PPA package path', function (): void {
    $script = file_get_contents(base_path('bin/install-orbit'));

    expect($script)
        ->toContain('download.docker.com')
        ->toContain('docker.gpg')
        ->toContain('docker-ce')
        ->toContain('ppa:ondrej/php')
        ->toContain('php8.4-cli')
        ->not->toContain('packages.sury.org/php')
        ->not->toContain('sury-php.gpg')
        ->not->toContain('ppa.launchpadcontent.net')
        ->not->toContain('keyserver.ubuntu.com');
});

it('waits for cloud-init before mutating apt on Ubuntu', function (): void {
    $script = file_get_contents(base_path('bin/install-orbit'));

    expect($script)->toContain('cloud-init status --wait');
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
TEXT;

    $process = new Process([
        'awk',
        '-f',
        base_path('bin/e2e-timings.awk'),
    ]);
    $process->setInput($input);
    $process->mustRun();

    $lines = collect(preg_split('/\R+/', trim($process->getOutput())) ?: [])
        ->filter()
        ->sort()
        ->values()
        ->all();

    expect($lines)->toBe([
        'node/new n=1 p50=9 p95=9',
        'topology/acquire n=3 p50=2.5 p95=3.75',
        'topology/reset n=1 p50=4 p95=4',
    ]);
});

it('does not install host Supervisor because runtime processes live inside Docker containers', function (): void {
    $script = file_get_contents(base_path('bin/install-orbit'));

    expect($script)
        ->not->toContain('supervisor')
        ->toContain('orbit-runtime:current');
});

it('installs the SSH client as a control-node provisioning prerequisite', function (): void {
    $script = file_get_contents(base_path('bin/install-orbit'));

    expect($script)->toContain('openssh-client');
});

it('does not install the SQLite CLI while providing host PHP SQLite support for the CLI executor', function (): void {
    $script = file_get_contents(base_path('bin/install-orbit'));

    expect(preg_match_all('/^\s+sqlite3\s+\\\\$/m', $script))->toBe(0)
        ->and($script)->not->toContain('php8.5-sqlite3')
        ->and($script)->toContain('php8.4-sqlite3')
        ->and($script)->toContain('orbit-runtime:current');
});

it('aligns orbit checkout ownership with the home parent so non-root users can write', function (): void {
    $script = file_get_contents(base_path('bin/install-orbit'));

    expect($script)
        ->toContain('finalize_target_ownership')
        ->toContain('--no-same-owner')
        ->toContain('chown -R');
});

it('documents e2e topology timing event names', function (): void {
    $testing = file_get_contents(base_path('TESTING.md'));

    expect($testing)
        ->toContain('batch.copy-start')
        ->toContain('agent-ready.<role>')
        ->toContain('command-ready.<role>')
        ->toContain('wireguard')
        ->toContain('cleanup.<role>');
});

it('does not expose stale per-topology feature e2e aliases', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts'])
        ->not->toHaveKey('test:e2e:features:control')
        ->not->toHaveKey('test:e2e:features:control-gateway')
        ->not->toHaveKey('test:e2e:features:control-gateway-dev')
        ->not->toHaveKey('test:e2e:features:control-gateway-dev-prod')
        ->not->toHaveKey('test:e2e:features:parallel')
        ->not->toHaveKey('test:e2e:features:docker:control-gateway-dev-prod');
});

it('runs the topology contract against the Docker full topology by default', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['test:e2e:topology-contract'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E=1 ORBIT_E2E_TOPOLOGY_PROVIDER=docker php artisan test --testsuite=E2E --group=e2e-topology-contract-operator_gateway_app-dev_app-prod --fail-on-empty-test-suite @additional_args',
    ])->and($composer['scripts'])
        ->not->toHaveKey('test:e2e:topology-contract:control')
        ->not->toHaveKey('test:e2e:topology-contract:control-gateway')
        ->not->toHaveKey('test:e2e:topology-contract:control-gateway-dev')
        ->not->toHaveKey('test:e2e:topology-contract:control-gateway-dev-prod');
});
