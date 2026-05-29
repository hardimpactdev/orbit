<?php

declare(strict_types=1);

use App\Console\Commands\E2EEnsureArtifactsCommand;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('is hidden', function (): void {
    $command = app(E2EEnsureArtifactsCommand::class);

    expect($command->isHidden())->toBeTrue();
});

it('requires explicit topology roles or runtime artifacts', function (): void {
    Process::fake();

    $this->artisan('e2e:ensure-artifacts')
        ->expectsOutputToContain('Set --roles or --all-roles for topology artifacts, or pass --runtime for Docker runtime/support images.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('rejects unsupported artifact lanes', function (): void {
    Process::fake();

    $this->artisan('e2e:ensure-artifacts', [
        '--lanes' => 'docker,hetzner',
        '--roles' => 'agent',
    ])
        ->expectsOutputToContain('Unsupported E2E artifact lane(s): hetzner. Supported lanes: docker, incus.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('plans targeted Docker and Incus role artifacts without mutation', function (): void {
    Process::fake();

    $this->artisan('e2e:ensure-artifacts', [
        'kind' => 'operator_gateway_agent',
        '--roles' => 'agent',
    ])
        ->expectsOutputToContain('Dry run. Pass --force to run supported artifact preparation.')
        ->expectsOutputToContain('planned: docker topology')
        ->expectsOutputToContain("command: composer e2e:prepare-docker-hosts -- --force --topology-only --roles=agent 'operator_gateway_agent'")
        ->expectsOutputToContain('planned: incus topology (force guarded)')
        ->expectsOutputToContain("command: composer e2e:prepare-topology -- --force --roles=agent 'operator_gateway_agent'")
        ->expectsOutputToContain('template: orbit-template-agent-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent-base)')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('delegates targeted Docker role preparation to the missing-only host preparer', function (): void {
    Process::fake([
        "composer e2e:prepare-docker-hosts -- --force --topology-only --roles=agent 'operator_gateway_app-dev_app-prod_agent'" => Process::result(),
    ]);

    $this->artisan('e2e:ensure-artifacts', [
        'kind' => 'operator_gateway_app-dev_app-prod_agent',
        '--lanes' => 'docker',
        '--roles' => 'agent',
        '--force' => true,
    ])
        ->expectsOutputToContain('ensured: docker topology')
        ->assertSuccessful();

    Process::assertRan("composer e2e:prepare-docker-hosts -- --force --topology-only --roles=agent 'operator_gateway_app-dev_app-prod_agent'");
});

it('can explicitly request a targeted Docker role rebuild', function (): void {
    Process::fake([
        "composer e2e:prepare-docker-hosts -- --force --rebuild --topology-only --roles=agent 'operator_gateway_app-dev_app-prod_agent'" => Process::result(),
    ]);

    $this->artisan('e2e:ensure-artifacts', [
        'kind' => 'operator_gateway_app-dev_app-prod_agent',
        '--lanes' => 'docker',
        '--roles' => 'agent',
        '--force' => true,
        '--rebuild' => true,
    ])
        ->expectsOutputToContain('ensured: docker topology')
        ->assertSuccessful();

    Process::assertRan("composer e2e:prepare-docker-hosts -- --force --rebuild --topology-only --roles=agent 'operator_gateway_app-dev_app-prod_agent'");
});

it('delegates Docker runtime preparation separately from topology roles', function (): void {
    Process::fake([
        "composer e2e:prepare-docker-hosts -- --force --runtime-only 'operator_gateway_app-dev_app-prod_agent'" => Process::result(),
    ]);

    $this->artisan('e2e:ensure-artifacts', [
        '--lanes' => 'docker',
        '--runtime' => true,
        '--force' => true,
    ])
        ->expectsOutputToContain('ensured: docker runtime')
        ->assertSuccessful();

    Process::assertRan("composer e2e:prepare-docker-hosts -- --force --runtime-only 'operator_gateway_app-dev_app-prod_agent'");
});

it('guards forced Incus role preparation before mutation', function (): void {
    Process::fake();

    $this->artisan('e2e:ensure-artifacts', [
        'kind' => 'operator_gateway_agent',
        '--lanes' => 'incus',
        '--roles' => 'agent',
        '--force' => true,
    ])
        ->expectsOutputToContain('Targeted Incus role artifact preparation is guarded.')
        ->assertFailed();

    Process::assertNothingRan();
});
