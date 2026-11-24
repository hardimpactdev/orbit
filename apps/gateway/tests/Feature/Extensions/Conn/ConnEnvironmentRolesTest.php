<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    app(ExtensionStore::class)->set('conn', true);
    SwampNodeFixture::ensure()->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);

    $this->dispatch = new class
    {
        /** @var list<int> */
        public array $started = [];

        public function start(Operation $operation): void
        {
            $this->started[] = $operation->id;
        }
    };
    app()->instance(SwampDispatch::class, $this->dispatch);

    $this->caller = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'macbook',
        'status' => 'active',
        'platform' => 'macos',
        'wireguard_ip' => '10.44.1.20',
        'public_ssh_host' => '10.44.1.20',
        'user' => 'nick',
    ]));
});

function roleEnvNode(string $name, string $ip, string $platform = 'linux', bool $primaryAgent = false): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name,
        'status' => 'active',
        'platform' => $platform,
        'wireguard_ip' => $ip,
        'public_ssh_host' => $ip,
        'user' => $platform === 'macos' ? 'nick' : 'orbit',
        'primary_agent' => $primaryAgent,
    ]));
}

function roleEnvHostServer(Node $host, string $environmentId, ?string $label = null): ConnEnvironment
{
    return ConnEnvironment::query()->create([
        'environment_id' => $environmentId,
        'label' => $label ?? $host->name,
        'url' => "http://{$host->wireguard_ip}:3773",
        'node_id' => $host->id,
        'admin_session' => 'fixture-admin-session',
        'admin_session_expires_at' => now()->addDays(10),
        'registered_at' => now()->subDay(),
    ]);
}

it('returns active roles and is_primary_agent false when no Node carries the agent role', function (): void {
    $beast = roleEnvNode('beast', '10.44.1.10');
    $beast->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $beast->roles()->create(['role' => RoleName::Database, 'status' => LifecycleStatus::Failed]);
    $plain = roleEnvNode('plain', '10.44.1.11', platform: 'macos');
    $this->caller->accessibleNodes()->attach([$beast->id, $plain->id]);

    roleEnvHostServer($beast, 'env-beast');
    roleEnvHostServer($plain, 'env-plain');

    $response = $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->getJson('/api/v1/conn/environments')
        ->assertOk();

    $byId = collect($response->json('data'))->keyBy('environment_id');

    expect($byId['env-beast']['roles'])->toBe(['app-dev'])
        ->and($byId['env-beast']['is_primary_agent'])->toBeFalse()
        ->and($byId['env-plain']['roles'])->toBe([])
        ->and($byId['env-plain']['is_primary_agent'])->toBeFalse();
});

it('falls back to the sole active agent-role Node as primary agent', function (): void {
    $mini = roleEnvNode('mini', '10.44.1.11', platform: 'macos');
    $mini->roles()->create(['role' => RoleName::Agent, 'status' => LifecycleStatus::Active]);
    $beast = roleEnvNode('beast', '10.44.1.10');
    $beast->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $this->caller->accessibleNodes()->attach([$mini->id, $beast->id]);

    roleEnvHostServer($mini, 'env-mini');
    roleEnvHostServer($beast, 'env-beast');

    $response = $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->getJson('/api/v1/conn/environments')
        ->assertOk();

    $byId = collect($response->json('data'))->keyBy('environment_id');

    expect($byId['env-mini']['roles'])->toBe(['agent'])
        ->and($byId['env-mini']['is_primary_agent'])->toBeTrue()
        ->and($byId['env-beast']['roles'])->toBe(['app-dev'])
        ->and($byId['env-beast']['is_primary_agent'])->toBeFalse();
});

it('marks no server as primary when several agent-role Nodes exist without an explicit choice', function (): void {
    $mini = roleEnvNode('mini', '10.44.1.11', platform: 'macos');
    $mini->roles()->create(['role' => RoleName::Agent, 'status' => LifecycleStatus::Active]);
    $vps = roleEnvNode('agent-vps', '10.44.1.12');
    $vps->roles()->create(['role' => RoleName::Agent, 'status' => LifecycleStatus::Active]);
    $this->caller->accessibleNodes()->attach([$mini->id, $vps->id]);

    roleEnvHostServer($mini, 'env-mini');
    roleEnvHostServer($vps, 'env-vps');

    $response = $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->getJson('/api/v1/conn/environments')
        ->assertOk();

    $byId = collect($response->json('data'))->keyBy('environment_id');

    expect($byId['env-mini']['roles'])->toBe(['agent'])
        ->and($byId['env-mini']['is_primary_agent'])->toBeFalse()
        ->and($byId['env-vps']['roles'])->toBe(['agent'])
        ->and($byId['env-vps']['is_primary_agent'])->toBeFalse();
});

it('honours explicit primary_agent designation and switches it atomically across Nodes', function (): void {
    $mini = roleEnvNode('mini', '10.44.1.11', platform: 'macos');
    $mini->roles()->create(['role' => RoleName::Agent, 'status' => LifecycleStatus::Active]);
    $vps = roleEnvNode('agent-vps', '10.44.1.12');
    $vps->roles()->create(['role' => RoleName::Agent, 'status' => LifecycleStatus::Active]);
    $this->caller->accessibleNodes()->attach([$mini->id, $vps->id]);

    roleEnvHostServer($mini, 'env-mini');
    roleEnvHostServer($vps, 'env-vps');

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->patchJson("/api/v1/nodes/{$mini->id}", ['primary_agent' => true])
        ->assertOk()
        ->assertJsonPath('data.primary_agent', true);

    $first = collect($this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->getJson('/api/v1/conn/environments')->assertOk()->json('data'))->keyBy('environment_id');

    expect($first['env-mini']['is_primary_agent'])->toBeTrue()
        ->and($first['env-vps']['is_primary_agent'])->toBeFalse();

    // Switching primary to agent-vps clears mini atomically.
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->patchJson("/api/v1/nodes/{$vps->id}", ['primary_agent' => true])
        ->assertOk()
        ->assertJsonPath('data.primary_agent', true);

    expect($mini->fresh()->primary_agent)->toBeFalse()
        ->and($vps->fresh()->primary_agent)->toBeTrue();

    // Repeating primary_agent=true on agent-vps is a no-op skip.
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->patchJson("/api/v1/nodes/{$vps->id}", ['primary_agent' => true])
        ->assertOk()
        ->assertJsonPath('meta.skipped', 'node.unchanged');

    $second = collect($this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->getJson('/api/v1/conn/environments')->assertOk()->json('data'))->keyBy('environment_id');

    expect($second['env-mini']['is_primary_agent'])->toBeFalse()
        ->and($second['env-vps']['is_primary_agent'])->toBeTrue();
});

it('includes registering Node roles on Instance servers while keeping is_primary_agent false', function (): void {
    $host = roleEnvNode('beast', '10.44.1.10', primaryAgent: true);
    $host->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $host->roles()->create(['role' => RoleName::Agent, 'status' => LifecycleStatus::Active]);
    $this->caller->accessibleNodes()->attach($host->id);

    $project = Project::query()->create([
        'name' => 'Orbit',
        'slug' => 'orbit',
        'repository_url' => 'https://github.com/hardimpactdev/orbit.git',
        'type' => ProjectType::LaravelApp,
        'source_access' => ProjectSourceAccess::GitHubApp,
        'default_branch' => 'main',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $host->id,
        'name' => 'feat-roles',
        'environment' => 'development',
        'branch' => 'feat/roles',
        'checkout_path' => '/srv/apps/orbit/feat-roles',
        'status' => InstanceState::Active,
    ]);

    roleEnvHostServer($host, 'env-host');
    $instanceEnv = ConnEnvironment::query()->create([
        'environment_id' => 'env-instance',
        'label' => 'Orbit / feat-roles',
        'url' => 'http://10.44.1.10:3901',
        'node_id' => $host->id,
        'admin_session' => 'fixture-admin-session',
        'admin_session_expires_at' => now()->addDays(10),
        'registered_at' => now()->subHour(),
    ]);
    $instanceEnv->forceFill(['instance_id' => $instance->id])->save();

    $response = $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->getJson('/api/v1/conn/environments')
        ->assertOk();

    $byId = collect($response->json('data'))->keyBy('environment_id');

    expect($byId['env-host']['roles'])->toBe(['app-dev', 'agent'])
        ->and($byId['env-host']['is_primary_agent'])->toBeTrue()
        ->and($byId['env-instance']['roles'])->toBe(['app-dev', 'agent'])
        ->and($byId['env-instance']['is_primary_agent'])->toBeFalse();
});

it('assigns and removes the light agent role on a macOS Node without Swamp runs and clears primary_agent on removal', function (): void {
    $mini = roleEnvNode('mini', '10.44.1.11', platform: 'macos');
    $this->caller->accessibleNodes()->attach($mini->id);

    // Refuses primary_agent before the Node has an active agent role.
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->patchJson("/api/v1/nodes/{$mini->id}", ['primary_agent' => true])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'node.agent_role_required');

    // Adding agent on macOS records an active assignment and a succeeded Operation with no Swamp run.
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson("/api/v1/nodes/{$mini->id}/roles", ['role' => 'agent'])
        ->assertAccepted()
        ->assertJsonPath('data.kind', 'node.role_add')
        ->assertJsonPath('data.status', OperationStatus::Succeeded->value);

    expect($mini->roles()->where('role', RoleName::Agent)->first()?->status)->toBe(LifecycleStatus::Active)
        ->and($this->dispatch->started)->toBe([]);

    // Now primary_agent=true succeeds.
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->patchJson("/api/v1/nodes/{$mini->id}", ['primary_agent' => true])
        ->assertOk()
        ->assertJsonPath('data.primary_agent', true);

    // Removing agent on macOS succeeds without Swamp and clears primary_agent.
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$mini->id}/roles/agent", ['force' => true])
        ->assertAccepted()
        ->assertJsonPath('data.kind', 'node.role_remove')
        ->assertJsonPath('data.status', OperationStatus::Succeeded->value);

    expect($mini->roles()->where('role', RoleName::Agent)->exists())->toBeFalse()
        ->and($mini->fresh()->primary_agent)->toBeFalse()
        ->and($this->dispatch->started)->toBe([]);
});

it('surfaces a Node as the Conn Server entry point for General threads once node:role:add records the agent role', function (): void {
    $vps = roleEnvNode('agent-vps', '10.44.1.12');
    $this->caller->accessibleNodes()->attach($vps->id);
    roleEnvHostServer($vps, 'env-vps');
    $host = fn (): array => collect($this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->getJson('/api/v1/conn/environments')->assertOk()->json('data'))->keyBy('environment_id')['env-vps'];

    expect($host())->toMatchArray(['roles' => [], 'is_primary_agent' => false]);

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson("/api/v1/nodes/{$vps->id}/roles", ['role' => 'agent'])
        ->assertAccepted()
        ->assertJsonPath('data.status', OperationStatus::Succeeded->value);

    // The sole agent-role Node is the primary agent without an explicit designation, and no host work ran.
    expect($host())->toMatchArray(['roles' => ['agent'], 'is_primary_agent' => true])
        ->and($this->dispatch->started)->toBe([]);

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$vps->id}/roles/agent", ['force' => true])
        ->assertAccepted();

    expect($host())->toMatchArray(['roles' => [], 'is_primary_agent' => false]);
});
