<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('documents docker runtime image preparation without force', function (): void {
    Process::fake();

    $this->artisan('e2e:prepare-docker-runtime')
        ->expectsOutputToContain('orbit-e2e-topology-runtime:current')
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    Process::assertNothingRan();
});

it('builds the docker runtime image when forced', function (): void {
    Process::fake([
        '*' => Process::result(),
    ]);

    $this->artisan('e2e:prepare-docker-runtime', ['--force' => true])
        ->expectsOutputToContain('Built orbit-e2e-topology-runtime:current.')
        ->assertSuccessful();

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker build')
        && str_contains($process->command, 'docker/e2e/topology/Dockerfile')
        && str_contains($process->command, 'orbit-e2e-topology-runtime:current')
        && str_contains($process->command, base_path()));
});

it('installs an orbit shim that resolves the current checkout artisan file', function (): void {
    $dockerfile = file_get_contents(base_path('docker/e2e/topology/Dockerfile'));

    expect($dockerfile)
        ->toContain('/usr/local/bin/orbit')
        ->toContain('if [ -f "$PWD/artisan" ]; then')
        ->toContain('exec php "$PWD/artisan" "$@"')
        ->toContain('exec php "$HOME/orbit/artisan" "$@"');
});

it('starts sshd for gateway to app node remote shell coverage', function (): void {
    $dockerfile = file_get_contents(base_path('docker/e2e/topology/Dockerfile'));

    expect($dockerfile)
        ->toContain('openssh-client')
        ->toContain('openssh-server')
        ->toContain('[program:sshd]')
        ->toContain('/usr/sbin/sshd -D')
        ->toContain('CMD ["/usr/local/bin/orbit-e2e-container"]');
});

it('runs supervisord under tini without shipping the gateway scheduler program', function (): void {
    $dockerfile = file_get_contents(base_path('docker/e2e/topology/Dockerfile'));

    expect($dockerfile)
        ->toContain('supervisor')
        ->toContain('tini')
        ->toContain('ENTRYPOINT ["/usr/bin/tini", "--"]')
        ->toContain('exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf')
        ->not->toContain('[program:orbit_scheduler]')
        ->not->toContain('command=/bin/bash -lc "php artisan orbit-scheduler --sleep-seconds=60"');
});

it('fails clearly when the docker build fails', function (): void {
    Process::fake([
        '*' => Process::result(errorOutput: 'docker build failed', exitCode: 1),
    ]);

    $this->artisan('e2e:prepare-docker-runtime', ['--force' => true])
        ->expectsOutputToContain('docker build failed')
        ->assertFailed();
});
