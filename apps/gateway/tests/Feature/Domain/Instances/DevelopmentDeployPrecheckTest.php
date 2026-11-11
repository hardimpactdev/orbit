<?php

declare(strict_types=1);

use App\Actions\Instances\ListInstancesAction;
use App\Data\Instances\InstanceData;
use App\Domain\Instances\Deployment\AutomaticDeployFireProjection;
use App\Domain\Instances\Deployment\AutomaticDeployPolicyWriter;
use App\Domain\Instances\Deployment\DevelopmentDeployPrecheck;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\NativeRouteUpdateProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Jobs\Instances\CheckDevelopmentDeployments;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

final class DevelopmentTickSocket implements WebSocketClient
{
    public array $frames = [];

    public bool $productionPaused = true;

    private bool $open = false;

    public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
    {
        $this->open = true;
    }

    public function send(array $message): void
    {
        $this->frames[] = $message;
        if ($message['type'] !== 'workflow.run' || $message['payload']['workflowIdOrName'] !== 'automatic-development-deploy') {
            $this->productionPaused = false;
            throw new RuntimeException('Development touched the paused production schedule.');
        }
    }

    public function receive(float $timeoutSeconds): array
    {
        $message = array_last($this->frames);

        return [['id' => $message['id'], 'type' => 'event', 'event' => ['kind' => 'started',
            'workflowName' => 'automatic-development-deploy', 'runId' => '11111111-1111-4111-8111-111111111111']]];
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function isConnected(): bool
    {
        return $this->open;
    }
}

function developmentTickDefault(string $name = 'default', RoleName $role = RoleName::AppDev, bool $container = true): Instance
{
    $node = Node::withoutEvents(fn () => Node::query()->create(['name' => 'node-'.bin2hex(random_bytes(4)), 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '127.0.0.1', 'user' => 'orbit']));
    $node->roles()->create(['role' => $role, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'tool', 'slug' => 'tool-'.bin2hex(random_bytes(4)), 'type' => 'node-package', 'repository_url' => 'https://github.com/example/tool-'.bin2hex(random_bytes(4)).'.git', 'default_branch' => '13.x']);

    $instance = Instance::withoutEvents(fn () => Instance::query()->create(['node_id' => $node->id, 'project_id' => $project->id,
        'name' => $name, 'checkout_path' => '/srv/tool/'.$name, 'status' => InstanceState::Active, 'source_layout' => 'checkout', 'seed_commit' => str_repeat('a', 40),
        'auto_deploy' => $container && $role === RoleName::AppDev]));
    if ($container) {
        $node->forceFill(['instance_bridge' => '10.253.'.$node->id.'.0/24'])->save();
        InstanceRuntime::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id,
            'incus_project' => 'orbit-instances', 'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.'.$node->id.'.2',
            'runtime_version' => 'r', 'agent_version' => 'a', 'power_state' => 'stopped', 'seed_commit' => str_repeat('a', 40)]);
    }

    return $instance;
}

it('does no Swamp work for matching defaults and reads only eligible Projects once', function (): void {
    $default = developmentTickDefault();
    developmentTickDefault('copy');
    developmentTickDefault(role: RoleName::AppProd);
    $worktree = developmentTickDefault(container: false);
    $worktree->update(['source_layout' => 'worktree']);
    $inactive = developmentTickDefault();
    $inactive->node->roles()->update(['status' => 'failed']);
    $inactiveNode = developmentTickDefault();
    $inactiveNode->node->update(['status' => 'failed']);
    $inactiveInstance = developmentTickDefault();
    $inactiveInstance->update(['status' => InstanceState::Reserved]);
    $ambiguous = developmentTickDefault();
    $ambiguous->node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $heads = Mockery::mock(RemoteBranchHeads::class);
    $heads->shouldReceive('head')->once()->withArgs(fn (Project $project, string $branch) => $project->id === $default->project_id && $branch === '13.x')->andReturn(str_repeat('a', 40));
    app()->instance(RemoteBranchHeads::class, $heads);
    $socket = new DevelopmentTickSocket;
    app()->instance(WebSocketClient::class, $socket);

    app(DevelopmentDeployPrecheck::class)->check();

    expect($socket->frames)->toBe([]);
});

it('starts only development policy for changed defaults and leaves production paused', function (): void {
    $default = developmentTickDefault();
    $production = developmentTickDefault(role: RoleName::AppProd);
    $production->update(['auto_deploy' => true]);
    $heads = Mockery::mock(RemoteBranchHeads::class);
    $heads->shouldReceive('head')->once()->withArgs(fn (Project $project, string $branch) => $project->id === $default->project_id && $branch === '13.x')->andReturn(str_repeat('b', 40));
    app()->instance(RemoteBranchHeads::class, $heads);
    $socket = new DevelopmentTickSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/test-ca', 'swamp.run_token' => 'test-token']);

    (new CheckDevelopmentDeployments)->handle(app(DevelopmentDeployPrecheck::class));

    expect($socket->productionPaused)->toBeTrue()
        ->and($socket->frames)->toHaveCount(1)
        ->and($socket->frames[0]['payload'])->toBe(['workflowIdOrName' => 'automatic-development-deploy', 'inputs' => ['instances' => [$default->id]]])
        ->and($production->fresh()->auto_deploy)->toBeTrue();
    $productionWorkflow = Yaml::parseFile(base_path('../../swamp/workflows/workflow-automatic-production-deploy.yaml'));
    expect($productionWorkflow['id'])->toBe(AutomaticDeployFireProjection::Workflow)
        ->and($productionWorkflow['trigger'])->toBe(['schedule' => '30 * * * * *']);
});

it('keeps checking other Projects when one head cannot be read', function (): void {
    developmentTickDefault();
    $second = developmentTickDefault();
    $heads = Mockery::mock(RemoteBranchHeads::class);
    $heads->shouldReceive('head')->once()->ordered()->andThrow(new RuntimeException('unavailable'));
    $heads->shouldReceive('head')->once()->ordered()->andReturn(str_repeat('b', 40));
    app()->instance(RemoteBranchHeads::class, $heads);
    $socket = new DevelopmentTickSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/test-ca', 'swamp.run_token' => 'test-token']);

    app(DevelopmentDeployPrecheck::class)->check();

    expect($socket->frames[0]['payload']['inputs'])->toBe(['instances' => [$second->id]]);
});

it('never selects a checkout default with auto-deploy on and a moved head', function (): void {
    $instance = developmentTickDefault(container: false);
    $instance->update(['auto_deploy' => true]);
    $heads = Mockery::mock(RemoteBranchHeads::class);
    $heads->shouldReceive('head')->andReturn(str_repeat('b', 40));
    app()->instance(RemoteBranchHeads::class, $heads);
    $socket = new DevelopmentTickSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/test-ca', 'swamp.run_token' => 'test-token']);

    app(DevelopmentDeployPrecheck::class)->check();

    expect($socket->frames)->toBe([]);
});

it('shares container eligibility across automatic admission discovery and Instance output without changing production', function (): void {
    $container = developmentTickDefault();
    $checkout = developmentTickDefault(container: false);
    $checkout->update(['auto_deploy' => true]);
    $unseeded = developmentTickDefault();
    $unseeded->runtime()->update(['seed_commit' => null]);
    $production = developmentTickDefault(role: RoleName::AppProd, container: false);
    $production->update(['auto_deploy' => true]);
    $disabledProduction = developmentTickDefault(role: RoleName::AppProd, container: false);
    $container->node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);

    $selected = app(ListInstancesAction::class)->handle($container->node, automaticDeploy: true);

    expect($selected->modelKeys())->toBe([$container->id, $production->id]);
    foreach ([$container, $production] as $instance) {
        AutomaticDeployPolicyWriter::Swamp->assertSwamp($instance);
        expect(InstanceData::fromModel($instance)->autoDeploy->eligible)->toBeTrue()
            ->and(InstanceData::fromModel($instance)->autoDeploy->enabled)->toBeTrue();
    }
    foreach ([$checkout, $unseeded] as $instance) {
        expect(fn () => AutomaticDeployPolicyWriter::Swamp->assertSwamp($instance))
            ->toThrow(ResourceOperationException::class, 'Checkout Instances are working trees and are never auto-deployed.');
        expect(AutomaticDeployPolicyWriter::Swamp->eligibilityRefusal($instance))->toBe(AutomaticDeployPolicyWriter::ContainerDefaultRequired)
            ->and(InstanceData::fromModel($instance)->autoDeploy->eligible)->toBeFalse()
            ->and(InstanceData::fromModel($instance)->autoDeploy->enabled)->toBeFalse();
    }
    expect(InstanceData::fromModel($container)->autoDeploy->developmentEligible)->toBeTrue();
    foreach ([$checkout, $unseeded] as $instance) {
        expect(InstanceData::fromModel($instance)->autoDeploy->developmentEligible)->toBeFalse();
    }
    foreach ([$production, $disabledProduction] as $instance) {
        expect(InstanceData::fromModel($instance)->autoDeploy->toArray())->not->toHaveKey('development_eligible');
    }
    expect(InstanceData::fromModel($disabledProduction)->autoDeploy->eligible)->toBeFalse();
    $production->node->roles()->update(['status' => 'failed']);
    expect(AutomaticDeployPolicyWriter::Swamp->eligibilityRefusal($production->fresh()))->toBe(AutomaticDeployPolicyWriter::InactiveRole);
});

it('exposes the configured merge check and placement-specific ungated warnings', function (): void {
    foreach ([RoleName::AppDev, RoleName::AppProd] as $role) {
        $instance = developmentTickDefault(role: $role);
        $instance->update(['auto_deploy' => true]);
        $instance->project->update(['merge_check' => 'Required checks']);
        $automatic = InstanceData::fromModel($instance)->autoDeploy;
        expect($automatic->gate)->toBe('Required checks')->and($automatic->warning)->toBeNull();
        $instance->project->update(['merge_check' => null]);
        expect(InstanceData::fromModel($instance)->autoDeploy->warning)
            ->toBe($role === RoleName::AppDev
                ? 'Project '.$instance->project->name.' has no merge check configured; every moved head deploys until one is set.'
                : 'The Project has no merge_check, so every new commit on the branch deploys without a check.');
    }
});

it('uses one flag for development selection admission output and operator control', function (): void {
    $instance = developmentTickDefault();
    Node::withoutEvents(fn () => $instance->node->update(['wireguard_ip' => '10.44.0.7']));
    $instance->node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.7']);
    $heads = Mockery::mock(RemoteBranchHeads::class);
    $heads->shouldReceive('head')->once()->andReturn(str_repeat('b', 40));
    app()->instance(RemoteBranchHeads::class, $heads);
    $socket = new DevelopmentTickSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/test-ca', 'swamp.run_token' => 'test-token']);
    $path = '/api/v1/instances/'.$instance->id;

    $this->postJson($path.'/auto-deploy/disable')->assertOk()->assertJsonPath('data.auto_deploy.enabled', false);
    app(DevelopmentDeployPrecheck::class)->check();
    expect($socket->frames)->toBe([])
        ->and(app(ListInstancesAction::class)->handle($instance->node, automaticDeploy: true)->modelKeys())->toBe([])
        ->and($instance->fresh()->auto_deploy)->toBeFalse();
    $this->withHeader('Idempotency-Key', 'disabled.development')->postJson($path.'/deploy/automatic/development', ['commit' => str_repeat('b', 40)])
        ->assertConflict()->assertJsonPath('error.code', 'deployment.paused');
    expect(Operation::query()->count())->toBe(0)->and($instance->deployments()->count())->toBe(0);

    $this->postJson($path.'/auto-deploy/enable')->assertOk()->assertJsonPath('data.auto_deploy.enabled', true);
    expect($instance->fresh()->auto_deploy)->toBeTrue();
    AutomaticDeployPolicyWriter::Swamp->assertSwamp($instance->fresh(), development: true);
    app(DevelopmentDeployPrecheck::class)->check();
    expect($socket->frames)->toHaveCount(1)
        ->and($socket->frames[0]['payload']['inputs'])->toBe(['instances' => [$instance->id]]);
});

it('keeps the production automatic switch contract unchanged', function (): void {
    $instance = developmentTickDefault(role: RoleName::AppProd, container: false);
    $instance->update(['branch' => '13.x']);
    Node::withoutEvents(fn () => $instance->node->update(['wireguard_ip' => '10.44.0.7']));
    $instance->node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.7']);
    $path = '/api/v1/instances/'.$instance->id.'/auto-deploy/';

    $this->postJson($path.'enable')->assertOk()->assertJsonPath('data.auto_deploy.enabled', true);
    $this->postJson($path.'disable')->assertOk()->assertJsonPath('data.auto_deploy.enabled', false);
    expect($instance->fresh()->auto_deploy)->toBeFalse();
});

it('refuses operator automatic controls for checkout and unseeded development defaults', function (bool $container, string $command): void {
    $instance = developmentTickDefault(container: $container);
    $instance->runtime()->update(['seed_commit' => null]);
    Node::withoutEvents(fn () => $instance->node->update(['wireguard_ip' => '10.44.0.7']));
    $instance->node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.7']);

    $this->postJson('/api/v1/instances/'.$instance->id.'/auto-deploy/'.$command)->assertConflict()
        ->assertJsonPath('error.code', AutomaticDeployPolicyWriter::ContainerDefaultRequired)
        ->assertJsonPath('error.message', 'Automatic development deployment requires an active container default with a seed snapshot. Checkout Instances are working trees and are never auto-deployed.');
})->with(['checkout' => false, 'unseeded container' => true])->with(['enable', 'disable']);

it('enables only existing ready development container defaults in an expand-only repeatable migration', function (): void {
    $ready = developmentTickDefault();
    $ready->update(['auto_deploy' => false]);
    $checkout = developmentTickDefault(container: false);
    $unseeded = developmentTickDefault();
    $unseeded->runtime()->update(['seed_commit' => null]);
    $other = developmentTickDefault('copy');
    $inactive = developmentTickDefault();
    $inactive->node->roles()->update(['status' => 'failed']);
    $production = developmentTickDefault(role: RoleName::AppProd);
    $instances = [$checkout, $unseeded, $other, $inactive, $production];
    foreach ($instances as $instance) {
        $instance->update(['auto_deploy' => false]);
    }
    $migration = require database_path('migrations/2027_02_04_000001_enable_ready_development_auto_deploy.php');

    $migration->up();
    $migration->up();
    $migration->down();

    expect($ready->fresh()->auto_deploy)->toBeTrue();
    foreach ($instances as $instance) {
        expect($instance->fresh()->auto_deploy)->toBeFalse();
    }
});

it('runs the unique development scan on the background tasks queue', function (): void {
    $job = new CheckDevelopmentDeployments;

    expect($job->connection)->toBe('gateway')->and($job->queue)->toBe('tasks')
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class);
});

it('prevents overlapping development scans after enqueue uniqueness expires', function (): void {
    $first = new CheckDevelopmentDeployments;
    $second = new CheckDevelopmentDeployments;
    $runs = 0;
    $unique = app(UniqueLock::class);
    expect($unique->acquire($first))->toBeTrue();
    $this->travel($first->uniqueFor + 1)->seconds();
    expect($unique->acquire($second))->toBeTrue();

    $first->middleware()[0]->handle($first, function () use ($second, &$runs): void {
        $runs++;
        $second->middleware()[0]->handle($second, function () use (&$runs): void {
            $runs++;
        });
        expect($runs)->toBe(1);
    });
    $second->middleware()[0]->handle($second, function () use (&$runs): void {
        $runs++;
    });

    expect($runs)->toBe(2);
    $unique->release($second);
    $this->travelBack();
});

it('registers an independent enabled minute tick', function (): void {
    $this->artisan('schedule:list')->assertSuccessful();
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => $event->description === CheckDevelopmentDeployments::class);

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('* * * * *');
});

it('projects an on-demand development fire once through the shared terminal reader', function (): void {
    $instance = developmentTickDefault();
    $projection = app(AutomaticDeployFireProjection::class);
    $fire = ['fire_json' => json_encode(['events' => [['instance' => $instance->id, 'key' => 'dev.policy.1.1',
        'body' => ['event' => 'activity', 'result' => 'started', 'commit' => str_repeat('b', 40)]]]], JSON_THROW_ON_ERROR)];
    $run = ['id' => '11111111-1111-4111-8111-111111111111'];

    $projection->applyScheduled(AutomaticDeployFireProjection::DevelopmentWorkflow, $run, $fire);
    $projection->applyScheduled(AutomaticDeployFireProjection::DevelopmentWorkflow, $run, $fire);

    expect(Activity::query()->where('description', 'auto_deploy.started')->count())->toBe(1);
    $definitions = app(DirectOperation::class)->scheduledResultDefinitions();
    expect($definitions[AutomaticDeployFireProjection::DevelopmentWorkflow]['projection'])->toBe(AutomaticDeployFireProjection::class);
});

it('uses the container snapshot seed for discovery and the policy API, including an empty seed', function (): void {
    $instance = developmentTickDefault();
    $runtime = $instance->runtime()->firstOrFail();
    $runtime->update(['seed_commit' => str_repeat('b', 40)]);
    $heads = Mockery::mock(RemoteBranchHeads::class);
    $heads->shouldReceive('head')->once()->andReturn(str_repeat('b', 40));
    app()->instance(RemoteBranchHeads::class, $heads);
    $socket = new DevelopmentTickSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/test-ca', 'swamp.run_token' => 'test-token']);

    app(DevelopmentDeployPrecheck::class)->check();

    expect($socket->frames)->toBe([])
        ->and(InstanceData::fromModel($instance->fresh())->seedCommit)->toBe(str_repeat('b', 40));
    $runtime->update(['seed_commit' => null]);
    app(DevelopmentDeployPrecheck::class)->check();
    expect($socket->frames)->toBe([])
        ->and(InstanceData::fromModel($instance->fresh())->seedCommit)->toBeNull()
        ->and(InstanceData::fromModel($instance->fresh())->autoDeploy->eligible)->toBeFalse();
});

it('settles a matching default publication through the Route owner without a development policy run', function (): void {
    $instance = developmentTickDefault();
    $instance->update(['development_projection_pending' => true]);
    $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id,
        'domain' => 'tool.orbit', 'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $operation = new Operation(['status' => OperationStatus::Succeeded,
        'input' => ['route_update' => ['route_id' => $route->id, 'result_route_id' => $route->id, 'selected' => [], 'effectful' => false]]]);
    $heads = Mockery::mock(RemoteBranchHeads::class);
    $heads->shouldReceive('head')->once()->andReturn(str_repeat('a', 40));
    app()->instance(RemoteBranchHeads::class, $heads);
    $socket = new DevelopmentTickSocket;
    app()->instance(WebSocketClient::class, $socket);

    app(NativeRouteUpdateProjection::class)->settle($operation);
    app(DevelopmentDeployPrecheck::class)->check();

    expect($instance->fresh()->development_projection_pending)->toBeFalse()->and($socket->frames)->toBe([]);
});

it('exports real off on responses and history for the development toggle matrix', function (): void {
    $instance = developmentTickDefault();
    Node::withoutEvents(fn () => $instance->node->update(['wireguard_ip' => '10.44.0.7']));
    $instance->node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.7']);
    $path = '/api/v1/instances/'.$instance->id;
    $cells = [];
    foreach (range(0, 3) as $failures) {
        if ($failures > 0) {
            InstanceDeployment::query()->create([
                'instance_id' => $instance->id,
                'kind' => 'deploy',
                'status' => 'failed',
                'commit' => str_repeat('b', 40),
                'triggered_by' => 'orbit:auto-deploy',
                'caller_node_id' => $instance->node_id,
                'failed_step' => 'Check out in the container',
                'error_code' => 'operation.native_failed',
                'started_at' => '2026-10-31T05:00:00+00:00',
                'finished_at' => '2026-10-31T05:00:00+00:00',
            ]);
        }
        $disabled = $this->postJson($path.'/auto-deploy/disable')->assertOk()->json('data.auto_deploy');
        $enabled = $this->postJson($path.'/auto-deploy/enable')->assertOk()->json('data.auto_deploy');
        $history = $this->getJson($path.'/deployments')->assertOk()->json('data');
        // Normalize only identities/names, never policy fields or row outcomes.
        $ids = array_reverse(array_column($history, 'id'));
        foreach ($history as &$row) {
            $row['id'] = array_search($row['id'], $ids, true) + 1;
            $row['instance_id'] = 7;
            $row['caller_node_id'] = 9;
            $row['caller_node_name'] = 'development-toggle-node';
        }
        unset($row);
        $cells[] = [
            'failures' => $failures,
            'disabled' => array_intersect_key($disabled, array_flip(['enabled', 'development_eligible', 'gate'])),
            'enabled' => array_merge(array_intersect_key($enabled, array_flip(['enabled', 'development_eligible', 'gate'])), [
                'resumed_after' => $enabled['resumed_after'] === 0 ? 0 : array_search($enabled['resumed_after'], $ids, true) + 1,
            ]),
            'history' => $history,
        ];
        expect($instance->fresh()->auto_deploy)->toBeTrue()
            ->and($instance->deployments()->count())->toBe($failures);
    }
    $fixture = base_path('../../swamp/extensions/behaviors/automatic-deploy/fixtures/development-toggle.json');
    if (getenv('UPDATE_DEVELOPMENT_TOGGLE_FIXTURE') === '1') {
        file_put_contents($fixture, json_encode($cells, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
    expect($cells)->toBe(json_decode(file_get_contents($fixture), true, flags: JSON_THROW_ON_ERROR));
});
