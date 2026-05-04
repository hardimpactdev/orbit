<?php

declare(strict_types=1);

use App\Console\Commands\E2EPreflightCommand;
use App\Console\Commands\E2EPrepareHcloudImagesCommand;
use App\Console\Commands\E2EPrepareIncusImagesCommand;
use App\Console\Commands\E2EPrepareTopologyCommand;
use App\Console\Commands\E2EReapHcloudCommand;
use App\Console\Commands\E2EReapIncusCommand;

it('keeps ephemeral e2e on the Incus backend separate from default pest tests', function (): void {
    expect(base_path('bin/e2e'))->not->toBeFile();
});

it('does not expose a standing live smoke test lane', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect(base_path('bin/live-smoke'))->not->toBeFile()
        ->and($composer['scripts'])->not->toHaveKey('test:live');
});

it('fails command docs lint when warnings are present', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['docs-lint'])
        ->toContain('--strict')
        ->toContain('--format=agent');
});

it('keeps the aggregate quality gate complete', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['quality-check'])->toBe([
        '@docs-lint',
        '@analyse',
        '@rector',
        '@format',
        '@test',
    ]);
});

it('runs ephemeral e2e through the opt-in Pest suite', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['test'])
        ->sequence(
            fn ($script) => $script->toContain('artisan config:clear'),
            fn ($script) => $script->toContain('pest --exclude-group=e2e'),
        );

    expect($composer['scripts']['test:e2e'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        '@test:e2e:provisioning',
        '@test:e2e:features',
    ]);
});

it('documents the two supported verification lanes', function (): void {
    $testing = file_get_contents(base_path('TESTING.md'));

    expect($testing)
        ->toContain('## In-Memory Pest')
        ->toContain('## Ephemeral VM E2E')
        ->toContain('composer test')
        ->toContain('composer test:e2e')
        ->not->toContain('composer test:live')
        ->not->toContain('bin/live-smoke')
        ->not->toContain('Standing Live Node Rule');
});

it('exposes the hcloud e2e resource reaper', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['e2e:reap-hcloud'])->toBe('@php artisan e2e:reap-hcloud');
});

it('exposes guarded hcloud e2e image preparation', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['e2e:prepare-hcloud-images'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        '@php artisan e2e:prepare-hcloud-images',
    ]);
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

it('registers the e2e artisan commands', function (): void {
    $commands = [
        E2EPreflightCommand::class,
        E2EPrepareIncusImagesCommand::class,
        E2EPrepareHcloudImagesCommand::class,
        E2EPrepareTopologyCommand::class,
        E2EReapIncusCommand::class,
        E2EReapHcloudCommand::class,
    ];

    foreach ($commands as $class) {
        expect(class_exists($class))->toBeTrue("{$class} does not exist");

        $command = app($class);
        expect($command->isHidden())->toBeTrue("{$class} should be hidden");
        expect($command->getDefinition()->hasOption('json'))->toBeTrue("{$class} should accept --json");
    }
});

it('installs the PHP toolchain via the reachable sury.org repo on Ubuntu', function (): void {
    $script = file_get_contents(base_path('bin/install-orbit'));

    expect($script)
        ->toContain('packages.sury.org/php')
        ->toContain('sury-php.gpg')
        ->toContain('APT::Update::Error-Mode=any')
        ->not->toContain('ppa.launchpadcontent.net')
        ->not->toContain('keyserver.ubuntu.com')
        ->not->toContain('add-apt-repository');
});

it('waits for cloud-init before mutating apt on Ubuntu', function (): void {
    $script = file_get_contents(base_path('bin/install-orbit'));

    expect($script)->toContain('cloud-init status --wait');
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
        ->toContain('ssh-authorize.<role>')
        ->toContain('ssh-ready.<role>')
        ->toContain('snapshot.<role>')
        ->toContain('wireguard')
        ->toContain('cleanup.<role>');
});

it('exposes opt-in parallel feature e2e after topology contracts', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['test:e2e:features:parallel'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        '@test:e2e:topology-contract',
        'ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e-feature --exclude-group=e2e-topology-contract --parallel --processes=3',
    ]);
});

it('exposes control-only feature e2e after the control topology contract', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['test:e2e:features'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        '@test:e2e:features:control',
        '@test:e2e:features:control-gateway-dev-prod',
    ])->and($composer['scripts']['test:e2e:features:control'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e-topology-contract-control --fail-on-empty-test-suite @no_additional_args',
        'ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e-feature-control --exclude-group=e2e-topology-contract @additional_args',
    ]);
});

it('exposes docker feature e2e only for topology groups with tests', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['test:e2e:features:docker'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        '@test:e2e:features:docker:control-gateway-dev-prod',
    ])->and($composer['scripts']['test:e2e:features:docker:control-gateway-dev-prod'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'ORBIT_E2E=1 ORBIT_E2E_TOPOLOGY_PROVIDER=docker php artisan test --testsuite=E2E --group=e2e-topology-contract-control-gateway-dev-prod --fail-on-empty-test-suite @no_additional_args',
        'ORBIT_E2E=1 ORBIT_E2E_TOPOLOGY_PROVIDER=docker php artisan test --testsuite=E2E --group=e2e-feature-control-gateway-dev-prod --exclude-group=e2e-topology-contract @additional_args',
    ])->and($composer['scripts'])->not->toHaveKey('test:e2e:features:docker:control')
        ->and($composer['scripts'])->not->toHaveKey('test:e2e:features:docker:control-gateway');
});
