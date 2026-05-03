<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('keeps ephemeral e2e on the Incus backend separate from default pest tests', function (): void {
    $script = base_path('bin/e2e');
    $contents = file_get_contents($script);

    expect($script)->toBeFile()
        ->and(is_executable($script))->toBeTrue()
        ->and($contents)->toContain('ORBIT_E2E_HOST')
        ->and($contents)->toContain('incus launch')
        ->and($contents)->toContain('orbit-e2e')
        ->and($contents)->toContain('Use Pest for in-memory command and contract tests')
        ->and($contents)->toContain('Use E2E for provisioning or host-mutating workflows');
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
        ->toContain('@php artisan test --exclude-group=e2e');

    expect($composer['scripts']['test:e2e'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e',
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

it('documents and prepares the reusable blank e2e image', function (): void {
    $script = file_get_contents(base_path('bin/e2e'));

    expect($script)
        ->toContain('--prepare-blank')
        ->toContain('ORBIT_E2E_BLANK_IMAGE')
        ->toContain('ORBIT_E2E_BOOTSTRAP_USER')
        ->toContain('orbit-blank-ubuntu-26.04')
        ->toContain('provisioner')
        ->toContain('ORBIT_E2E_BOOTSTRAP_USER must be a non-orbit Linux username')
        ->toContain('incus publish "$name" --force --reuse --alias "$blank_image"')
        ->toContain('source_image="$1"')
        ->toContain('blank_image="$2"');
});

it('documents and prepares the reusable ready control e2e image', function (): void {
    $script = file_get_contents(base_path('bin/e2e'));

    expect($script)
        ->toContain('--prepare-control')
        ->toContain('--control')
        ->toContain('ORBIT_E2E_CONTROL_IMAGE')
        ->toContain('ORBIT_E2E_CONTROL_USER')
        ->toContain('orbit-ready-control')
        ->toContain('control')
        ->toContain('ORBIT_E2E_CONTROL_USER must be a non-orbit Linux username')
        ->toContain('bin/install-orbit')
        ->toContain("usermod -p '*'")
        ->toContain('--role=control')
        ->toContain('--path=/home/${control_user}/orbit')
        ->toContain('orbit --version');
});

it('documents the node:new first-gateway ephemeral e2e lane', function (): void {
    $script = file_get_contents(base_path('bin/e2e'));

    expect($script)
        ->toContain('--node-new-gateway')
        ->toContain('node:new gateway-1')
        ->toContain('--role=gateway')
        ->toContain('--ssh-user=${bootstrap_user}')
        ->toContain('--control-name=control-1')
        ->toContain('gateway-1 not found in registry')
        ->toContain('control-1 not found in registry')
        ->toContain('Orbit not installed under /home/orbit/orbit on gateway')
        ->toContain('orbit --version did not work on gateway as orbit user')
        ->toContain('orbit node:list')
        ->toContain('node:new gateway passed');
});

it('documents node:new app-node ephemeral e2e lanes', function (): void {
    $script = file_get_contents(base_path('bin/e2e'));

    expect($script)
        ->toContain('--node-new-devapp')
        ->toContain('--node-new-prodapp')
        ->toContain('node_new_app development')
        ->toContain('node_new_app production')
        ->toContain('orbit node:new ${orbit_node_name} --role=app')
        ->toContain('--environment=${node_environment}')
        ->toContain('--tld=${node_tld}')
        ->toContain('development_tld')
        ->toContain('production app-node response included development_tld')
        ->toContain('Orbit not installed under /home/orbit/orbit on app node')
        ->toContain('node:new ${node_environment} app passed');
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

it('rejects orbit as an ephemeral e2e bootstrap or control user', function (): void {
    $script = escapeshellarg(base_path('bin/e2e'));

    $bootstrapUser = Process::run("ORBIT_E2E_BOOTSTRAP_USER=orbit {$script} --preflight");
    $controlUser = Process::run("ORBIT_E2E_CONTROL_USER=orbit {$script} --preflight");

    expect($bootstrapUser->exitCode())->toBe(2)
        ->and($bootstrapUser->errorOutput())->toContain('ORBIT_E2E_BOOTSTRAP_USER must be a non-orbit Linux username')
        ->and($controlUser->exitCode())->toBe(2)
        ->and($controlUser->errorOutput())->toContain('ORBIT_E2E_CONTROL_USER must be a non-orbit Linux username');
});
