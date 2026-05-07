<?php

declare(strict_types=1);

use App\Console\Commands\E2EPrepareDockerHostsCommand;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('is hidden', function (): void {
    $command = app(E2EPrepareDockerHostsCommand::class);

    expect($command->isHidden())->toBeTrue();
});

it('documents host preparation without force using docker host slots', function (): void {
    Process::fake();

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_HOST_SLOTS' => 'sidecar1:2,sidecar2:2,beast:3',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-hosts', ['kind' => 'control-gateway-dev-prod'])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('planned: sidecar1')
            ->expectsOutputToContain('planned: sidecar2')
            ->expectsOutputToContain('planned: beast')
            ->assertSuccessful();
    });

    Process::assertNothingRan();
});

it('prepares runtime and topology images on every configured docker host', function (): void {
    $runs = [];

    Process::fake(function ($process) use (&$runs) {
        $runs[] = [
            'command' => $process->command,
            'environment' => $process->environment,
        ];

        return Process::result();
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_HOST_SLOTS' => 'sidecar1:2,sidecar2:2,beast:3',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-hosts', [
            'kind' => 'control-gateway-dev-prod',
            '--force' => true,
        ])->assertSuccessful();
    });

    expect($runs)->toHaveCount(6)
        ->and($runs[0]['environment'])->toMatchArray(['DOCKER_HOST' => 'ssh://sidecar1'])
        ->and($runs[0]['command'])->toContain('composer e2e:prepare-docker-runtime -- --force')
        ->and($runs[1]['environment'])->toMatchArray(['DOCKER_HOST' => 'ssh://sidecar1'])
        ->and($runs[1]['command'])->toContain('composer e2e:prepare-docker-topology -- --force')
        ->and($runs[2]['environment'])->toMatchArray(['DOCKER_HOST' => 'ssh://sidecar2'])
        ->and($runs[4]['environment'])->toMatchArray(['DOCKER_HOST' => 'ssh://beast']);
});

it('supports preparing only topology images', function (): void {
    Process::fake([
        '*' => Process::result(),
    ]);

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_HOSTS' => 'beast',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-hosts', [
            'kind' => 'control-gateway-dev-prod',
            '--force' => true,
            '--topology-only' => true,
        ])->assertSuccessful();
    });

    Process::assertRan(fn ($process): bool => str_contains($process->command, 'composer e2e:prepare-docker-topology -- --force'));
    Process::assertNotRan(fn ($process): bool => str_contains($process->command, 'composer e2e:prepare-docker-runtime'));
});

it('fails when one host preparation fails and reports the host', function (): void {
    Process::fake(function ($process) {
        $host = $process->environment['DOCKER_HOST'] ?? null;

        if ($host === 'ssh://sidecar2') {
            return Process::result(output: 'build failed', exitCode: 1);
        }

        return Process::result();
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_HOSTS' => 'sidecar1,sidecar2',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-hosts', [
            'kind' => 'control-gateway-dev-prod',
            '--force' => true,
        ])
            ->expectsOutputToContain('sidecar2')
            ->assertFailed();
    });
});
