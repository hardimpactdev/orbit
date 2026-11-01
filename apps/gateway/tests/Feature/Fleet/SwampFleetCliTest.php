<?php

declare(strict_types=1);

use App\Domain\Fleet\CliConvergePlan;
use App\Domain\Fleet\CliReleaseCatalog;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Nodes\NodeCliFootprint;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    FleetFixtures::node('gateway-cli', [RoleName::Gateway])->update(['wireguard_ip' => '10.44.0.1']);
    app()->instance(AgentCommandRunner::class, new FakeAgentCommandRunner);
});

it('pins the current desired release and profile without running a Node effect', function (): void {
    $node = FleetFixtures::node('pinned-cli', [RoleName::AppDev]);
    $input = app(CliConvergePlan::class)->input($node);
    $desired = app(DesiredFleetState::class)->current()->cli;
    expect($input['release'])->toBe(['version' => $desired->version, 'url' => $desired->assets[0]->url, 'sha256' => $desired->assets[0]->sha256])
        ->and($input['profile'])->toBe(NodeCliFootprint::configuration('10.44.0.1'))
        ->and($input['skip_reason'])->toBeNull()->and($input['plan_error'])->toBeNull()
        ->and(app(AgentCommandRunner::class)->calls)->toBe([]);
});

it('includes a first workload role while provisioning but excludes a removing role', function (): void {
    $node = FleetFixtures::node('first-role-cli', [RoleName::AppDev]);
    $node->roles()->update(['status' => LifecycleStatus::Provisioning]);
    expect(app(CliConvergePlan::class)->exclusion($node))->toBeNull();
    $node->roles()->update(['status' => LifecycleStatus::Removing]);
    expect(app(CliConvergePlan::class)->exclusion($node))->toBe('roleless');
});

it('records a desired-release read failure for the optional child instead of executing an unpinned install', function (): void {
    $catalog = Mockery::mock(CliReleaseCatalog::class);
    $catalog->shouldReceive('find')->once()->andThrow(new RuntimeException('Release lookup did not answer.'));
    app()->instance(CliReleaseCatalog::class, $catalog);
    $node = FleetFixtures::node('cli-read-error', [RoleName::AppDev]);
    $input = app(CliConvergePlan::class)->input($node);
    expect($input['plan_error'])->toBe('fleet.cli_plan_failed')->and($input['release'])->toBeNull()
        ->and(app(AgentCommandRunner::class)->calls)->toBe([]);
});
