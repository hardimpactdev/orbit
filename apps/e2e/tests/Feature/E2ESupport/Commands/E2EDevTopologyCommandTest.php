<?php

declare(strict_types=1);

it('renders a stable dry-run json contract without acquiring providers', function (): void {
    $result = run_e2e_script([
        PHP_BINARY,
        'bin/e2e-dev-topology',
        '--dry-run',
        '--json',
        '--kind=operator_gateway_app-dev',
        '--provider=docker',
    ]);

    expect($result['exit_code'])->toBe(0, $result['stderr']);

    $payload = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);

    expect($payload['success']['dev_topology']['id'])->toBe('dry-run')
        ->and($payload['success']['dev_topology']['dry_run'])->toBeTrue()
        ->and($payload['success']['dev_topology']['provider'])->toBe('docker')
        ->and($payload['success']['dev_topology']['kind'])->toBe('operator_gateway_app-dev')
        ->and($payload['success']['dev_topology']['checkout_roles'])->toBe(['operator', 'gateway', 'app-dev'])
        ->and($payload['success']['dev_topology']['release_command'])->toBe('composer e2e:dev-topology:release -- dry-run')
        ->and($payload['success']['dev_topology']['shell_command'])->toContain('composer e2e:dev-topology -- --kind=operator_gateway_app-dev --provider=docker');
});

it('renders human dry-run output with the release command shape', function (): void {
    $result = run_e2e_script([
        PHP_BINARY,
        'bin/e2e-dev-topology',
        '--dry-run',
        '--kind=operator_gateway_app-dev',
        '--provider=docker',
    ]);

    expect($result['exit_code'])->toBe(0, $result['stderr'])
        ->and($result['stdout'])->toContain('Retained topology dry run')
        ->and($result['stdout'])->toContain('Source-checkout E2E remains the normal feature loop')
        ->and($result['stdout'])->toContain('composer e2e:dev-topology:release -- dry-run')
        ->and($result['stdout'])->not->toContain('binary acceptance replaces');
});

it('rejects unsupported topology kinds with a stable json error', function (): void {
    $result = run_e2e_script([
        PHP_BINARY,
        'bin/e2e-dev-topology',
        '--dry-run',
        '--json',
        '--kind=missing-kind',
    ]);

    expect($result['exit_code'])->toBe(1);

    $payload = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'error' => [
            'code' => 'validation_failed',
            'message' => 'Unsupported E2E topology kind [missing-kind].',
        ],
    ]);
});

it('routes composer dev topology scripts through apps e2e only', function (): void {
    $rootComposer = json_decode((string) file_get_contents(repo_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $e2eComposer = json_decode((string) file_get_contents(repo_path('apps/e2e/composer.json')), true, flags: JSON_THROW_ON_ERROR);

    $rootAcquire = implode("\n", (array) ($rootComposer['scripts']['e2e:dev-topology'] ?? []));
    $rootRelease = implode("\n", (array) ($rootComposer['scripts']['e2e:dev-topology:release'] ?? []));
    $e2eAcquire = implode("\n", (array) ($e2eComposer['scripts']['e2e:dev-topology'] ?? []));
    $e2eRelease = implode("\n", (array) ($e2eComposer['scripts']['e2e:dev-topology:release'] ?? []));

    expect($rootAcquire)->toContain('composer --working-dir=apps/e2e e2e:dev-topology')
        ->and($rootRelease)->toContain('composer --working-dir=apps/e2e e2e:dev-topology:release')
        ->and($e2eAcquire)->toContain('php bin/e2e-dev-topology')
        ->and($e2eRelease)->toContain('php bin/e2e-dev-topology-release')
        ->and($rootAcquire.$rootRelease.$e2eAcquire.$e2eRelease)->not->toContain('orbit-gateway-artisan')
        ->and($rootAcquire.$rootRelease.$e2eAcquire.$e2eRelease)->not->toContain('apps/gateway/artisan');
});
