<?php

declare(strict_types=1);

use App\Actions\Fleet\AdmitAgentToolsAction;
use App\Actions\Fleet\AdmitFleetRebootAction;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Fleet\AgentTools;
use App\Domain\Fleet\AgentToolsContribution;
use App\Domain\Fleet\AgentToolsProjection;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Jobs\Fleet\AdmitAgentTools;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Domain\AiRouterAgentTools;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\Conn\Domain\ConnAgentTools;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

function agentToolsNode(string $name, string $address, array $extra = []): Node
{
    return Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => $name, 'public_ssh_host' => $address, 'wireguard_ip' => $address,
        'user' => 'nckrtl', 'ssh_host_fingerprint' => 'SHA256:'.$name, 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => $name.'-key',
        'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', ...$extra]));
}

function agentToolsServer(Node $node, string $url): ConnEnvironment
{
    return ConnEnvironment::query()->create(['environment_id' => 'env-'.$node->name.'-'.parse_url($url, PHP_URL_PORT), 'label' => $node->name, 'url' => $url,
        'node_id' => $node->id, 'admin_session' => 'private-admin', 'admin_session_expires_at' => now()->addDays(20), 'registered_at' => now()]);
}

/** The router Process as it runs on services: `env` first, then `<folder>/current/cli-proxy-api -config <file>`. */
function agentToolsRouter(Node $services, string $name = 'cli-proxy-api'): Process
{
    return Process::query()->create(['owner_type' => Node::class, 'owner_id' => $services->id, 'name' => $name, 'runtime' => 'systemd',
        'working_directory' => '/home/orbit/.local/share/cliproxyapi', 'restart_policy' => 'on-failure', 'runtime_config' => ['command' => [
            '/usr/bin/env', 'MANAGEMENT_STATIC_PATH=/home/orbit/.local/share/cliproxyapi/static', '/home/orbit/.local/share/cliproxyapi/current/cli-proxy-api',
            '-config', '/home/orbit/.config/cliproxyapi/config.yaml']]]);
}

beforeEach(function (): void {
    config(['fleet.agent_tools' => true, 'swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' control']);
    SwampNodeFixture::ensure();
    $this->gateway = agentToolsNode('gateway', '10.44.0.2', ['user' => 'orbit']);
    app(GatewayServingHost::class)->remember($this->gateway);
    $this->beast = agentToolsNode('beast', '10.44.0.7');
    $this->shark = agentToolsNode('shark', '10.44.0.11');
    $this->services = agentToolsNode('services', '10.44.0.3', ['user' => 'orbit']);
    $this->mac = agentToolsNode('mini', '10.44.0.9', ['platform' => 'macos', 'architecture' => 'arm64']);
    agentToolsServer($this->beast, 'http://10.44.0.7:3773');
    agentToolsServer($this->shark, 'http://10.44.0.11:3773');
    agentToolsServer($this->mac, 'http://10.44.0.9:3773');
    app(ExtensionStore::class)->set('conn', true);
    app(ExtensionStore::class)->set('ai-router', true);
    app(AiRouterState::class)->enable($this->services->id, 'services-valkey', 'http://10.44.0.3:8317', 'management', 'read', 'control');
    $this->router = agentToolsRouter($this->services);
});

it('wires both contributions into the plug-in point', function (): void {
    $classes = array_map(get_class(...), iterator_to_array(app()->tagged(AgentToolsContribution::class), false));

    expect($classes)->toContain(ConnAgentTools::class, AiRouterAgentTools::class);
});

it('updates the Linux Nodes that run their own T3 server, never a Mac, a container server or a task VM', function (): void {
    // sabre registered a server at another Node's address: its own host runs none. Its container's server names an Instance.
    $sabre = agentToolsNode('sabre', '10.44.0.10');
    agentToolsServer($sabre, 'http://10.44.0.7:3801');
    // The test's transaction never commits, so a deferred check never asks for the Instance row.
    DB::statement('PRAGMA defer_foreign_keys = ON');
    ConnEnvironment::query()->forceCreate(['environment_id' => 'env-container', 'label' => 'container', 'url' => 'http://10.44.0.10:3800',
        'node_id' => $sabre->id, 'instance_id' => 4242, 'admin_session' => 'other', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
    $guest = agentToolsNode('beast-tvm-1', '10.44.0.151', ['user' => 'orbit']);
    TaskVm::query()->create(['host_node_id' => $this->beast->id, 'node_id' => $guest->id, 'name' => 'beast-tvm-1', 'state' => TaskVmState::Ready,
        'wireguard_ip' => '10.44.0.151', 'provider' => 'incus', 'capacity_pool' => 'task']);
    agentToolsServer($guest, 'http://10.44.0.151:3773');
    $unpinned = agentToolsNode('polar', '10.44.0.8', ['ssh_host_fingerprint' => null]);
    agentToolsServer($unpinned, 'http://10.44.0.8:3773');

    $hosts = app(AgentTools::class)->hosts();

    expect(array_column($hosts, 'name'))->toBe(['beast', 'shark'])
        ->and($hosts[0])->toBe(['node_id' => $this->beast->id, 'name' => 'beast', 'host' => '10.44.0.7', 'user' => 'nckrtl', 'port' => 22,
            'host_keys' => ['ssh-ed25519 beast-key'], 'identity_file' => '/etc/orbit/swamp-role-worker/ssh-identity']);
});

it('names no host while Conn is off', function (): void {
    app(ExtensionStore::class)->set('conn', false);

    expect(app(AgentTools::class)->hosts())->toBe([]);
});

it('names the router Process, its folder, its configuration and the restart the Process restart workflow runs', function (): void {
    $router = app(AgentTools::class)->router();

    expect($router)->toMatchArray(['node_id' => $this->services->id, 'name' => 'services', 'host' => '10.44.0.3', 'user' => 'orbit',
        'process_id' => $this->router->id, 'folder' => '/home/orbit/.local/share/cliproxyapi', 'config' => '/home/orbit/.config/cliproxyapi/config.yaml',
        'repository' => 'router-for-me/CLIProxyAPI', 'asset' => 'linux_amd64', 'keep' => 2, 'model' => 'claude-haiku-5-5'])
        ->and($router['restart']['control'])->toMatchArray(['process_id' => $this->router->id, 'runtime' => 'systemd',
            'name' => 'orbit-process-'.$this->router->id.'-cli-proxy-api.service', 'on_demand' => false, 'vite' => null])
        ->and($router['restart']['ssh_execution']['target'])->toMatchArray(['host' => '10.44.0.3', 'user' => 'orbit']);
});

it('names no router while the AI router is off, not set up, or runs no single CLIProxyAPI Process', function (Closure $change): void {
    $change($this);

    expect(app(AgentTools::class)->router())->toBeNull();
})->with([
    'extension off' => [fn ($test) => app(ExtensionStore::class)->set('ai-router', false)],
    'not set up' => [fn ($test) => app(AiRouterState::class)->disable()],
    'two routers' => [fn ($test) => agentToolsRouter($test->services, 'cli-proxy-api-2')],
    'no configuration' => [fn ($test) => $test->router->update(['runtime_config' => ['command' => ['/home/orbit/.local/share/cliproxyapi/current/cli-proxy-api']]])],
    'no router' => [fn ($test) => $test->router->delete()],
]);

it('admits one run on the Swamp Node with the packages, the hosts and the router, and starts its workflow', function (): void {
    config(['swamp.run_token' => 'test-token', 'swamp.read_token' => 'read-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
    $frames = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $frame) use (&$frames): void {
        $frames[] = $frame;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frames): array {
        return [['type' => 'event', 'id' => end($frames)['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-fleet-agent-tools',
            'runId' => '0198e15c-bf97-7c23-8f1f-61b8fe67a845']]];
    });
    app()->instance(WebSocketClient::class, $socket);

    $operation = app(AdmitAgentToolsAction::class)->execute();

    expect($operation->kind)->toBe('fleet.agent_tools')
        ->and($operation->node_id)->toBe(SwampNodeFixture::ensure()->id)
        ->and($operation->input['intent']['packages'])->toBe(['@anthropic-ai/claude-code', '@openai/codex', '@xai-official/grok', 'opencode-ai'])
        ->and(array_column($operation->input['intent']['hosts'], 'name'))->toBe(['beast', 'shark'])
        ->and($operation->input['intent']['router']['process_id'])->toBe($this->router->id)
        ->and($frames[0]['payload']['workflowIdOrName'])->toBe('operation-fleet-agent-tools')
        ->and($frames[0]['payload']['inputs']['intent'])->toBe($operation->input['intent']);
});

it('refuses a second run, waits for a reboot pass, and makes the reboot pass wait for it', function (): void {
    $open = Operation::query()->create(['kind' => AgentTools::Kind, 'target_type' => 'fleet', 'target_id' => 1, 'node_id' => null, 'status' => 'running', 'input' => []]);
    expect(fn () => app(AdmitAgentToolsAction::class)->execute())
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('fleet.agent_tools_busy'))
        ->and(fn () => app(AdmitFleetRebootAction::class)->execute())
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('fleet.reboot_waiting'));

    $open->update(['status' => 'succeeded']);
    Operation::query()->create(['kind' => 'fleet.reboot', 'target_type' => 'fleet', 'target_id' => 1, 'node_id' => null, 'status' => 'running', 'input' => []]);
    expect(fn () => app(AdmitAgentToolsAction::class)->execute())
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('fleet.agent_tools_waiting'));
});

it('refuses when it is off or has nothing to update, and the daily job only logs those refusals', function (): void {
    config(['fleet.agent_tools' => false]);
    expect(fn () => app(AdmitAgentToolsAction::class)->execute())
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('fleet.agent_tools_not_ready'));

    config(['fleet.agent_tools' => true]);
    app(ExtensionStore::class)->set('conn', false);
    app(ExtensionStore::class)->set('ai-router', false);
    expect(fn () => app(AdmitAgentToolsAction::class)->execute())
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('fleet.agent_tools_not_ready'));

    new AdmitAgentTools()->handle(app(AdmitAgentToolsAction::class));
    expect(Operation::query()->where('kind', AgentTools::Kind)->count())->toBe(0);
});

it('records each host in Node order with its tools, marks an unreached host failed and keeps the router', function (): void {
    $operation = Operation::query()->create(['kind' => AgentTools::Kind, 'target_type' => 'fleet', 'target_id' => 1, 'node_id' => SwampNodeFixture::ensure()->id,
        'status' => 'running', 'state' => ['swamp_run_id' => 'run-1'], 'input' => ['intent' => ['packages' => [], 'router' => ['node_id' => $this->services->id, 'name' => 'services'],
            'hosts' => [['node_id' => $this->beast->id, 'name' => 'beast'], ['node_id' => $this->shark->id, 'name' => 'shark']]]]]);
    $tool = ['name' => '@openai/codex', 'method' => 'vite-plus', 'before' => '0.167.0', 'after' => '0.168.0', 'outcome' => 'updated', 'secret' => 'dropped'];

    $projected = app(AgentToolsProjection::class)->apply($operation, ['run_id' => 'run-1', 'status' => 'failed', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'error_code' => 'fleet.agent_tools_failed', 'failure' => null, 'result' => [
            'hosts' => [['node_id' => $this->beast->id, 'name' => 'beast', 'outcome' => 'updated', 'error_code' => null, 'tools' => [$tool]]],
            'router' => ['node_id' => $this->services->id, 'name' => 'services', 'outcome' => 'updated', 'before' => '8.0.63', 'latest' => '8.0.70', 'after' => '8.0.70', 'pruned' => ['8.0.4']],
        ]]);

    expect($projected->status->value)->toBe('failed')
        ->and($projected->result['hosts'])->toBe([
            ['node_id' => $this->beast->id, 'name' => 'beast', 'outcome' => 'updated', 'error_code' => null, 'tools' => [array_diff_key($tool, ['secret' => true])]],
            ['node_id' => $this->shark->id, 'name' => 'shark', 'outcome' => 'failed', 'error_code' => 'fleet.agent_tools_host_unreachable', 'tools' => []],
        ])
        ->and($projected->result['router'])->toMatchArray(['outcome' => 'updated', 'before' => '8.0.63', 'after' => '8.0.70', 'pruned' => ['8.0.4']])
        ->and($projected->result['error_code'])->toBe('fleet.agent_tools_host_unreachable');
});

it('records a run without a router record as a failed router, and refuses an unknown outcome', function (): void {
    $operation = Operation::query()->create(['kind' => AgentTools::Kind, 'target_type' => 'fleet', 'target_id' => 1, 'node_id' => SwampNodeFixture::ensure()->id,
        'status' => 'running', 'state' => ['swamp_run_id' => 'run-2'], 'input' => ['intent' => ['packages' => [], 'hosts' => [],
            'router' => ['node_id' => $this->services->id, 'name' => 'services']]]]);
    $data = ['run_id' => 'run-2', 'status' => 'failed', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'failure' => null];

    expect(fn () => app(AgentToolsProjection::class)->apply($operation, [...$data, 'result' => ['hosts' => [], 'router' => ['outcome' => 'maybe']]]))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('operation.result_invalid'));

    $projected = app(AgentToolsProjection::class)->apply($operation, [...$data, 'result' => ['hosts' => [], 'router' => null]]);
    expect($projected->result['router'])->toBe(['node_id' => $this->services->id, 'name' => 'services', 'outcome' => 'failed', 'error_code' => 'fleet.agent_tools_router_failed'])
        ->and($projected->result['error_code'])->toBe('fleet.agent_tools_router_failed');
});
