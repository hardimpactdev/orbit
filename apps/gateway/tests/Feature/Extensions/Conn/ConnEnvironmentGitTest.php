<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Http\Mcp\ApiDispatcher;
use App\Http\Mcp\ApiOperationTool;
use App\Http\Mcp\ToolManifest;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Mcp\Request as McpRequest;
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

it('ignores unknown include values exactly as the existing list does', function (string $query): void {
    connGitServer('instance-server', true);
    $this->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', 'e439a946-a2a9-402d-b6cd-4cde4f962152');
    $before = $this->getJson('/api/v1/conn/environments')->assertOk()->getContent();
    $after = $this->getJson('/api/v1/conn/environments?'.$query)->assertOk()->getContent();
    expect($after)->toBe($before);
})->with(['other word' => ['include=everything'], 'empty' => ['include='], 'array' => ['include[]=git'],
    'number' => ['include=1'], 'padded git' => ['include=%20git%20']]);

it('keeps the default response byte identical to the existing list contract', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-13T00:00:00Z'));
    $server = connGitServer('instance-server', true);
    $requestId = 'e439a946-a2a9-402d-b6cd-4cde4f962152';
    $response = $this->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $requestId)->getJson('/api/v1/conn/environments')->assertOk();
    $expected = ['data' => [[
        'environment_id' => 'instance-server', 'label' => 'instance-server', 'url' => 'http://10.44.0.7:3800',
        'server_version' => null, 'registered_by' => 'fixture-host', 'registered_at' => '2026-11-13T00:00:00+00:00',
        'admin_session_expires_at' => '2026-11-14T00:00:00+00:00', 'status' => 'registered',
        'cleanup_pending' => false, 'cleanup_version' => 0, 'instance_id' => $server->instance_id,
        'project_id' => $server->instance->project_id, 'project_name' => 'instance-server', 'project_slug' => 'instance-server',
        'instance_name' => 'feature', 'node_name' => 'fixture-host', 'instance_url' => null, 'power_state' => 'stopped',
    ]], 'meta' => ['request_id' => $requestId]];
    expect($response->getContent())->toBe(json_encode($expected, JSON_THROW_ON_ERROR));
});

it('exposes the Git include through the generated list tool at the same API boundary', function (): void {
    connGitServer('instance-server', true);
    $definition = collect(ToolManifest::default()->definitions())->firstWhere('name', 'conn-environment-list');
    $tool = new ApiOperationTool($definition, app(ApiDispatcher::class));
    $caller = HttpRequest::create('/mcp', 'POST', server: ['REMOTE_ADDR' => $this->phone->wireguard_ip]);
    $response = $tool->handle(new McpRequest(['include' => 'git']), $caller);
    $data = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
    expect($data['data'][0]['git'])->toBe(['state' => 'unknown', 'reason' => 'not_observed']);
});
