<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRemovalOperationAction;
use App\Actions\Nodes\RemoveNodeAction;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeSettingsOperationCompletion;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Models\Schedule;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\TaskVmSettingsBuilder;

beforeEach(function (): void {
    app()->instance(OperationKinds::class, new OperationKinds(app(), ['node.remove' => [
        'executor' => 'swamp', 'completion' => NodeSettingsOperationCompletion::class, 'runs' => [],
        'completion_contract' => ['step' => 'intent-only', 'transport' => 'gateway', 'name' => 'intent-only', 'result' => []], 'result_fields' => ['error_code'],
    ]]));
});

it('refuses protected Nodes before making an offline reachability probe', function (RoleName $role): void {
    $caller = FleetFixtures::node('removal-caller');
    $node = FleetFixtures::node('removal-protected', [$role]);
    $probe = Mockery::mock(NodeReachabilityProbe::class);
    $probe->shouldNotReceive('degradation');
    app()->instance(NodeReachabilityProbe::class, $probe);

    expect(fn () => app(AdmitNodeRemovalOperationAction::class)->execute($node, $caller, true, true))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('node.'.$role->value.'_removal_forbidden'));

    expect(Operation::query()->count())->toBe(0);
    $this->assertModelExists($node);
})->with([RoleName::Gateway, RoleName::Vpn]);

it('retains Route associations and roles while recording each serving cleanup child', function (): void {
    $caller = FleetFixtures::node('removal-intent-caller');
    $node = FleetFixtures::node('removal-intent-source', [RoleName::AppProd]);
    $serving = FleetFixtures::node('removal-intent-serving', [RoleName::AppDev]);
    $project = Project::query()->create(['slug' => 'removal-intent', 'name' => 'Removal intent', 'repository_url' => 'https://github.com/example/removal-intent.git']);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'domain' => 'removal.private.test',
        'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $probe = Mockery::mock(NodeReachabilityProbe::class);
    $probe->shouldReceive('degradation')->once()->andReturn(ExporterDegradationReason::Unreachable);
    app()->instance(NodeReachabilityProbe::class, $probe);

    $operation = app(RemoveNodeAction::class)->start($node, $caller, true, true);

    expect($operation->state['removal_intent'])->toMatchArray(['route_ids' => [$route->id], 'roles' => ['app-prod'], 'offline' => true]);
    $intents = $operation->state['child_intents'];
    expect(array_find($intents, static fn (array $intent): bool => $intent['key'] === 'remove-residue-'.$serving->id))
        ->toBe(['key' => 'remove-residue-'.$serving->id, 'kind' => 'route.offline_residue_clean', 'node_id' => $serving->id, 'input' => ['removed_node_id' => $node->id]]);
    expect($operation->input['_node_plan'][0])->toMatchArray(['kind' => 'node.role_remove', 'node_id' => $node->id,
        'input' => ['role' => 'app-prod', 'purge_data' => false, 'offline' => true]]);
    $this->assertDatabaseHas('routes', ['id' => $route->id, 'node_id' => $node->id]);
    $this->assertDatabaseHas('node_roles', ['node_id' => $node->id, 'role' => 'app-prod', 'status' => 'active']);
    $this->assertDatabaseHas('nodes', ['id' => $node->id, 'status' => 'active']);
});

it('does not shed a reachable Nodes roles despite the offline claim', function (): void {
    $caller = FleetFixtures::node('removal-live-caller');
    $node = FleetFixtures::node('removal-live-source', [RoleName::AppProd]);
    $probe = Mockery::mock(NodeReachabilityProbe::class);
    $probe->shouldReceive('degradation')->once()->andReturnNull();
    app()->instance(NodeReachabilityProbe::class, $probe);

    expect(fn () => app(AdmitNodeRemovalOperationAction::class)->execute($node, $caller, true, true))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('node.has_roles'));

    expect(Operation::query()->count())->toBe(0);
    $this->assertDatabaseHas('node_roles', ['node_id' => $node->id, 'status' => 'active']);
});

it('requires force for an actual offline removal before recording its Operation', function (): void {
    $caller = FleetFixtures::node('removal-consent-caller');
    $node = FleetFixtures::node('removal-consent-source');
    $probe = Mockery::mock(NodeReachabilityProbe::class);
    $probe->shouldReceive('degradation')->once()->andReturn(ExporterDegradationReason::Unreachable);
    app()->instance(NodeReachabilityProbe::class, $probe);

    expect(fn () => app(AdmitNodeRemovalOperationAction::class)->execute($node, $caller, true))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('node.confirmation_required'));

    expect(Operation::query()->count())->toBe(0);
});

it('replays recorded removal before probing a changed source again', function (): void {
    $caller = FleetFixtures::node('removal-replay-caller');
    $node = FleetFixtures::node('removal-replay-source');
    $identity = app(OperationIdempotency::class);
    $identity->kind = 'node.remove';
    $identity->key = 'removal-replay';
    $identity->fingerprint = 'removal-replay-body';
    $probe = Mockery::mock(NodeReachabilityProbe::class);
    $probe->shouldNotReceive('degradation');
    app()->instance(NodeReachabilityProbe::class, $probe);
    $operation = app(AdmitNodeRemovalOperationAction::class)->execute($node, $caller);
    $node->update(['status' => 'failed']);

    $retry = app(AdmitNodeRemovalOperationAction::class)->execute($node->fresh(), $caller);

    expect($retry->id)->toBe($operation->id);
    expect($retry->state['removal_intent']['prior_status'])->toBe('active');
});

it('leaves state intact when another Operation holds the Node', function (): void {
    $caller = FleetFixtures::node('removal-held-caller');
    $node = FleetFixtures::node('removal-held-source');
    Operation::query()->create(['kind' => 'node.settings', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'lock_key' => 'node:'.$node->id, 'input' => [], 'status' => 'queued', 'sequence' => 1, 'attempts' => 0]);

    expect(fn () => app(AdmitNodeRemovalOperationAction::class)->execute($node, $caller))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));

    $this->assertDatabaseHas('nodes', ['id' => $node->id, 'status' => 'active']);
    expect(Operation::query()->count())->toBe(1);
});

it('preserves resource guards before any removal admission or offline probe', function (string $resource, string $error): void {
    $caller = FleetFixtures::node('remove-guard-caller');
    $node = FleetFixtures::node('remove-guard-source');
    $probe = Mockery::mock(NodeReachabilityProbe::class);
    $probe->shouldNotReceive('degradation');
    app()->instance(NodeReachabilityProbe::class, $probe);
    match ($resource) {
        'self' => $node = $caller,
        'instance' => $node->instances()->create(['project_id' => Project::query()->create(['slug' => 'guard', 'name' => 'Guard', 'repository_url' => 'https://github.com/example/guard.git'])->id,
            'name' => 'dev', 'checkout_path' => '/srv/orbit/apps/guard/dev', 'branch' => 'main', 'starting_commit' => str_repeat('a', 40), 'status' => 'active']),
        'database' => DatabaseServer::query()->create(['slug' => 'guard-db', 'node_id' => $node->id, 'tag' => '8', 'port' => 3306, 'root_password' => 'fixture-only', 'status' => 'active']),
        'schedule' => Schedule::query()->create(['target_type' => Node::class, 'target_id' => $node->id, 'host_node_id' => $node->id,
            'name' => 'guard', 'calendar' => 'daily', 'command' => 'true', 'timeout_seconds' => 3600, 'desired_timer_state' => 'enabled', 'status' => 'active']),
        'host' => app()->instance(TaskVmSettings::class, TaskVmSettingsBuilder::off(['hosts' => [TaskVmSettingsBuilder::host($node->id)]])),
        'role' => $node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']),
        'firewall' => $node->firewallRules()->create(['name' => 'https', 'action' => 'allow', 'source' => 'any', 'protocol' => 'tcp', 'port' => '443', 'status' => 'active']),
        'process' => $node->processes()->create(['name' => 'guard', 'runtime' => 'docker', 'working_directory' => '/app', 'runtime_config' => ['image' => 'postgres:18', 'command' => ['postgres']],
            'restart_policy' => 'unless-stopped', 'desired_state' => 'stopped', 'status' => 'active']),
        'route' => Route::query()->create(['kind' => 'custom_proxy', 'node_id' => $node->id, 'domain' => 'guard.test',
            'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending'])->update(['status' => 'active']),
    };

    expect(fn () => app(AdmitNodeRemovalOperationAction::class)->execute($node, $caller))
        ->toThrow(fn (ResourceOperationException $refusal) => expect($refusal->errorCode)->toBe($error));

    expect(Operation::query()->count())->toBe(0);
    $this->assertDatabaseHas('nodes', ['id' => $node->id, 'status' => 'active']);
})->with([
    ['self', 'node.self_removal_forbidden'], ['instance', 'node.has_instances'], ['database', 'node.has_database_servers'],
    ['schedule', 'schedule.target_in_use'], ['host', 'node.task_vm_host_configured'], ['role', 'node.has_roles'],
    ['firewall', 'node.has_firewall_rules'], ['process', 'node.has_processes'], ['route', 'route.reconciliation_required'],
]);

it('refuses Swamp removal of an executor Node before probing or shedding intent', function (): void {
    $caller = FleetFixtures::node('executor-remove-caller');
    $node = FleetFixtures::node('executor-removal');
    config(['dependency_detection.services_node_id' => $node->id]);
    $probe = Mockery::mock(NodeReachabilityProbe::class);
    $probe->shouldNotReceive('degradation');
    app()->instance(NodeReachabilityProbe::class, $probe);
    expect(fn () => app(RemoveNodeAction::class)->start($node, $caller, true, true))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('node.executor_dependency'));
    expect(Operation::query()->count())->toBe(0);
    $this->assertModelExists($node);
});
