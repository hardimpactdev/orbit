<?php

declare(strict_types=1);

use App\Services\E2E\HcloudDockerE2ERunner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    app()->instance(HcloudDockerE2ERunner::class, new HcloudDockerE2ERunner(retrySleepSeconds: 0));

    Process::preventStrayProcesses();
});

it('shows the hcloud docker test plan without side effects by default', function (): void {
    Process::fake();

    $this->artisan('e2e:test-hcloud-docker')
        ->expectsOutputToContain('Dry run. Pass --force to create a temporary Hetzner Docker host.')
        ->expectsOutputToContain('planned: create Hetzner server in nbg1')
        ->expectsOutputToContain('planned: run composer test:e2e:docker against Docker on that server')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('creates a temporary hcloud docker host, runs docker e2e, and cleans up', function (): void {
    Process::fake([
        'hcloud server ip *' => Process::result(output: "203.0.113.42\n"),
        '*' => Process::result(),
    ]);

    $this->artisan('e2e:test-hcloud-docker', [
        '--force' => true,
        '--processes' => 3,
        '--kind' => 'operator_gateway',
    ])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'hcloud ssh-key create')
        && str_contains($process->command, 'orbit-e2e-purpose=docker-host'));

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'hcloud server create')
        && str_contains($process->command, '--location')
        && str_contains($process->command, 'nbg1')
        && str_contains($process->command, '--label')
        && str_contains($process->command, 'orbit-e2e-purpose=docker-host'));

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'ssh ')
        && str_contains($process->command, 'root@203.0.113.42')
        && str_contains($process->command, 'docker info'));

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'composer e2e:prepare-docker-hosts -- --force')
        && str_contains($process->command, 'operator_gateway')
        && ($process->environment['ORBIT_E2E_DOCKER_HOSTS'] ?? null) === 'root@203.0.113.42');

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'composer test:e2e:docker')
        && ($process->environment['ORBIT_E2E_DOCKER_HOSTS'] ?? null) === 'root@203.0.113.42'
        && ($process->environment['ORBIT_E2E_PARALLEL_PROCESSES'] ?? null) === '3');

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'hcloud server delete'));
    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'hcloud ssh-key delete'));
});

it('can lease the hcloud docker resource shape from configured slots', function (): void {
    Process::fake([
        'hcloud server ip *' => Process::result(output: "203.0.113.42\n"),
        '*' => Process::result(),
    ]);

    withE2EProviderEnvironment([
        'ORBIT_E2E_HCLOUD_RESOURCE_SLOTS' => 'fsn1/cpx31/ubuntu-24.04:1',
    ], function (): void {
        $this->artisan('e2e:test-hcloud-docker', [
            '--force' => true,
        ])->assertSuccessful();
    });

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'hcloud server create')
        && str_contains($process->command, '--type')
        && str_contains($process->command, 'cpx31')
        && str_contains($process->command, '--image')
        && str_contains($process->command, 'ubuntu-24.04')
        && str_contains($process->command, '--location')
        && str_contains($process->command, 'fsn1'));
});

// Real 5s sleep between docker-info retries inside `HcloudDockerE2ERunner`.
// Tagged `slow` so default `composer test` excludes it; the full CI gate keeps
// retry coverage via `composer test:slow`.
it('waits until docker info succeeds on the hcloud host', function (): void {
    $dockerInfoAttempts = 0;

    Process::fake(function (PendingProcess $process) use (&$dockerInfoAttempts) {
        if (str_contains($process->command, 'hcloud server ip')) {
            return Process::result(output: "203.0.113.42\n");
        }

        if (str_contains($process->command, 'docker info')) {
            $dockerInfoAttempts++;

            return $dockerInfoAttempts === 1
                ? Process::result(exitCode: 1, errorOutput: "not ready\n")
                : Process::result();
        }

        return Process::result();
    });

    $this->artisan('e2e:test-hcloud-docker', [
        '--force' => true,
        '--prefix' => 'orbit-e2e-test',
        '--timeout' => 60,
    ])->assertSuccessful();

    expect($dockerInfoAttempts)->toBe(2);
})->group('slow');

it('exposes composer script for hcloud docker e2e', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['test:e2e:hcloud-docker'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; php artisan e2e:test-hcloud-docker @additional_args',
    ]);
});
