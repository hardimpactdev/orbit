<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('shows the hcloud image preparation plan without side effects by default', function (): void {
    Process::fake();

    $this->artisan('e2e:prepare-hcloud-images')
        ->expectsOutputToContain('Dry run. Pass --force to create hcloud image snapshots.')
        ->expectsOutputToContain('control -> orbit-ready-control')
        ->expectsOutputToContain('gateway -> orbit-ready-gateway')
        ->expectsOutputToContain('devapp -> orbit-ready-devapp')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('prepares control, gateway, and devapp hcloud snapshots when forced', function (): void {
    Process::fake([
        'hcloud server ip *' => Process::result(output: "203.0.113.10\n"),
        '*' => Process::result(),
    ]);

    $this->artisan('e2e:prepare-hcloud-images', ['--force' => true])
        ->expectsOutputToContain('Prepared 3 hcloud E2E image snapshots.')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'tar')
        && str_contains($process->command, 'orbit-source.tar.gz'));

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'hcloud ssh-key create')
        && str_contains($process->command, 'orbit-e2e=true')
        && str_contains($process->command, 'orbit-e2e-purpose=image-prepare'));

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'hcloud server create')
        && str_contains($process->command, 'orbit-e2e-image=')
        && str_contains($process->command, 'control')
        && str_contains($process->command, 'ubuntu-24.04'));

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'hcloud server create')
        && str_contains($process->command, 'orbit-e2e-image=')
        && str_contains($process->command, 'gateway')
        && str_contains($process->command, 'ubuntu-24.04'));

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, '/tmp/install-orbit --role=control')
        && str_contains($process->command, '--path=/home/control/orbit')
        && str_contains($process->command, '--php=8.5'));

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, '/tmp/install-orbit --role=gateway')
        && str_contains($process->command, '--path=/home/orbit/orbit')
        && str_contains($process->command, '--php=8.5'));

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'orbit:internal:bootstrap-gateway-local gateway 10.6.0.2'));

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'hcloud server create')
        && str_contains($process->command, 'orbit-e2e-image=')
        && str_contains($process->command, 'devapp')
        && str_contains($process->command, 'ubuntu-24.04'));

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, '/tmp/install-orbit --role=app')
        && str_contains($process->command, '--path=/home/orbit/orbit')
        && str_contains($process->command, '--php=8.5'));

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'hcloud server create-image')
        && str_contains($process->command, 'orbit-ready-control')
        && str_contains($process->command, 'orbit-e2e-image='));

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'hcloud server create-image')
        && str_contains($process->command, 'orbit-ready-gateway')
        && str_contains($process->command, 'orbit-e2e-image='));

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'hcloud server create-image')
        && str_contains($process->command, 'orbit-ready-devapp')
        && str_contains($process->command, 'orbit-e2e-image='));

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'hcloud server delete'));
    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'hcloud ssh-key delete'));
});

it('can prepare only the devapp hcloud snapshot', function (): void {
    Process::fake([
        'hcloud server ip *' => Process::result(output: "203.0.113.10\n"),
        '*' => Process::result(),
    ]);

    $this->artisan('e2e:prepare-hcloud-images', [
        '--force' => true,
        '--role' => 'devapp',
    ])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'orbit-e2e-image=')
        && str_contains($process->command, 'devapp'));
    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, '/tmp/install-orbit --role=app'));
    Process::assertDidntRun(fn (PendingProcess $process): bool => str_contains($process->command, 'orbit:internal:bootstrap-gateway-local'));
});

it('can prepare only the control hcloud snapshot', function (): void {
    Process::fake([
        'hcloud server ip *' => Process::result(output: "203.0.113.10\n"),
        '*' => Process::result(),
    ]);

    $this->artisan('e2e:prepare-hcloud-images', [
        '--force' => true,
        '--role' => 'control',
    ])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'orbit-e2e-image=')
        && str_contains($process->command, 'control'));
    Process::assertDidntRun(fn (PendingProcess $process): bool => str_contains($process->command, 'orbit-e2e-image=')
        && str_contains($process->command, 'gateway'));
});

it('requires a valid hcloud image role', function (): void {
    Process::fake();

    $this->artisan('e2e:prepare-hcloud-images', ['--role' => 'database'])
        ->expectsOutputToContain('--role must be one of: all, control, gateway, devapp')
        ->assertFailed();

    Process::assertNothingRan();
});

it('requires a supported prepared hcloud image PHP version', function (): void {
    Process::fake();

    $this->artisan('e2e:prepare-hcloud-images', ['--php' => '8.4'])
        ->expectsOutputToContain('--php must be: 8.5')
        ->assertFailed();

    Process::assertNothingRan();
});
