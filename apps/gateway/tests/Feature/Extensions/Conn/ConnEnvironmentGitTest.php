<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    app(ExtensionStore::class)->set('conn', true);
    Http::preventStrayRequests();
    Process::preventStrayProcesses();
    $this->mock(WebSocketClient::class)->shouldNotReceive('connect');
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'fixture-host', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'user' => 'orbit',
    ]));
    $this->node->forceFill(['instance_bridge' => '10.201.0.0/24'])->save();
    $this->phone = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'fixture-phone', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.77', 'public_ssh_host' => '10.44.0.77', 'user' => 'orbit',
    ]));
    $this->phone->accessibleNodes()->attach($this->node->id);
});

function connGitServer(string $name, bool $instanceBacked): ConnEnvironment
{
    $instance = null;
    if ($instanceBacked) {
        $project = Project::query()->create(['name' => $name, 'slug' => $name,
            'repository_url' => 'https://github.com/fixture/'.$name.'.git', 'default_branch' => 'main', 'root' => '.']);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => test()->node->id,
            'name' => 'feature', 'checkout_path' => '/fixture/'.$name, 'source_layout' => 'checkout', 'status' => 'active']);
        $instance->runtime()->create(['node_id' => test()->node->id, 'incus_project' => 'orbit-instances',
            'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.201.0.'.($instance->id + 10),
            'runtime_version' => 'fixture', 'agent_version' => 'fixture', 'power_state' => 'stopped']);
    }

    return ConnEnvironment::query()->forceCreate(['environment_id' => $name, 'label' => $name, 'node_id' => test()->node->id,
        'instance_id' => $instance?->id, 'url' => 'http://10.44.0.7:3800', 'admin_session' => 'fixture-session',
        'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
}

it('adds unknown Git only when requested and only for Instance servers without contacting a collector', function (): void {
    connGitServer('instance-server', true);
    connGitServer('node-server', false);
    $this->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip]);
    $before = $this->getJson('/api/v1/conn/environments')->assertOk()->json('data');
    foreach ($before as $row) {
        expect($row)->not->toHaveKey('git');
    }

    $after = $this->getJson('/api/v1/conn/environments?include=git')->assertOk()->json('data');
    expect($after[0]['git'])->toBe(['state' => 'unknown', 'reason' => 'not_observed'])
        ->and($after[1])->not->toHaveKey('git');
    unset($after[0]['git']);
    expect($after)->toBe($before);
    Http::assertNothingSent();
});

it('keeps Git list queries constant as fixture Instances grow', function (): void {
    connGitServer('first', true);
    $this->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip]);
    $this->getJson('/api/v1/conn/environments?include=git')->assertOk();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson('/api/v1/conn/environments?include=git')->assertOk()->assertJsonCount(1, 'data');
    $small = count(DB::getQueryLog());
    DB::disableQueryLog();
    for ($index = 0; $index < 8; $index++) {
        connGitServer('extra-'.$index, true);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $data = $this->getJson('/api/v1/conn/environments?include=git')->assertOk()->assertJsonCount(9, 'data')->json('data');
    $large = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($large)->toBe($small);
    foreach ($data as $row) {
        expect($row['git'])->toBe(['state' => 'unknown', 'reason' => 'not_observed']);
    }
});

it('keeps the existing Node access filter for Git requests', function (): void {
    connGitServer('hidden', true);
    $this->phone->accessibleNodes()->detach();
    $this->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
        ->getJson('/api/v1/conn/environments?include=git')->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
});

it('rejects an unsupported include', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
        ->getJson('/api/v1/conn/environments?include=everything')->assertUnprocessable();
});
