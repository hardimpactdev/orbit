<?php

declare(strict_types=1);

use App\Domain\Firewall\FirewallIntentProjection;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

function denyHttpNode(string $name, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => $ip,
        'public_ssh_port' => 2222, 'wireguard_ip' => $ip, 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-host-key',
    ]));
}

/** @param array<string, mixed> $input */
function denyHttp(array $input): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => test()->caller->wireguard_ip])
        ->postJson('/api/v1/nodes/'.test()->denyNode->id.'/firewall-rules/deny', $input);
}

/** Project an execution result, without running or deciding anything on the Gateway. */
function denyHttpComplete(Operation $operation, string $status): Operation
{
    return app(FirewallIntentProjection::class)->apply($operation, [
        'run_id' => $operation->state['swamp_run_id'], 'status' => $status,
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'error_code' => $status === 'succeeded' ? null : 'firewall.apply_failed',
        'result' => $status === 'succeeded' ? ['backend' => 'active'] : ['error_code' => 'firewall.apply_failed'],
    ]);
}

beforeEach(function (): void {
    SwampNodeFixture::ensure();
    Http::preventStrayRequests();
    $this->caller = denyHttpNode('operator', '10.44.0.9');
    $this->denyNode = denyHttpNode('target', '10.44.0.2');
    $this->caller->accessibleNodes()->attach($this->denyNode);
    $key = base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32));
    config(['swamp.ssh_public_key' => 'ssh-ed25519 '.$key.' fixture', 'swamp.address' => 'swamp.test',
        'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'fixture-run-token']);
    $this->socket = new class implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        public array $frames = [];

        private bool $open = false;

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->frames);

            return [['id' => $frame['id'], 'type' => 'event', 'event' => [
                'kind' => 'started', 'workflowName' => $frame['payload']['workflowIdOrName'], 'runId' => (string) Str::uuid(),
            ]]];
        }

        public function close(): void
        {
            $this->open = false;
        }

        public function isConnected(): bool
        {
            return $this->open;
        }
    };
    app()->instance(WebSocketClient::class, $this->socket);
});

it('captures normalized deny intent and submits only the native workflow', function (): void {
    denyHttp(['port' => '08080', 'source' => '192.0.2.67/24', 'label' => 'blocked clients'])->assertStatus(202);

    $rule = $this->denyNode->firewallRules()->sole();
    $operation = Operation::query()->sole();
    expect($rule->action->value)->toBe('deny')
        ->and($rule->port)->toBe('8080')->and($rule->protocol)->toBe('any')->and($rule->source)->toBe('192.0.2.0/24')
        ->and($operation->kind)->toBe('firewall.deny')
        ->and($operation->input)->toMatchArray(['rule_id' => $rule->id, 'caller_node_id' => $this->caller->id])
        ->and($operation->input['firewall']['rule'])->toMatchArray([
            'action' => 'deny', 'port' => '8080', 'protocol' => 'any', 'source' => '192.0.2.0/24',
        ])
        ->and($operation->input['firewall']['ensure'])->toBe('present')
        ->and($this->socket->frames)->toHaveCount(1)
        ->and($this->socket->frames[0]['type'])->toBe('workflow.run')
        ->and($this->socket->frames[0]['payload']['inputs']['intent'])->toBe($operation->input['firewall']);
    Http::assertNothingSent();
});

it('rejects invalid HTTP inputs without records or a native submission', function (array $input): void {
    denyHttp($input)->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');

    expect($this->denyNode->firewallRules()->count())->toBe(0)
        ->and(Operation::query()->count())->toBe(0)->and($this->socket->frames)->toBe([]);
})->with([
    'missing port' => [[]],
    'service name' => [['port' => 'ssh']],
    'reverse range' => [['port' => '8100:8000']],
    'port outside bounds' => [['port' => '65536']],
    'explicit any protocol' => [['port' => '8080', 'protocol' => 'any']],
    'invalid source' => [['port' => '8080', 'source' => '192.0.2.300']],
    'long label' => [['port' => '8080', 'label' => str_repeat('x', 256)]],
]);

it('requires Node access before admitting a deny', function (): void {
    $this->caller->accessibleNodes()->detach();

    denyHttp(['port' => '8080'])->assertStatus(403)->assertJsonPath('error.code', 'node_access.required');
    expect(Operation::query()->count())->toBe(0)->and($this->denyNode->firewallRules()->count())->toBe(0)
        ->and($this->socket->frames)->toBe([]);
});

it('refuses non-Linux targets without records or Node work', function (): void {
    $this->denyNode->update(['platform' => 'darwin']);

    denyHttp(['port' => '8080'])->assertStatus(422)->assertJsonPath('error.code', 'firewall.platform_unsupported');
    expect(Operation::query()->count())->toBe(0)->and($this->denyNode->firewallRules()->count())->toBe(0)
        ->and($this->socket->frames)->toBe([]);
});

it('protects SSH, recovery, WireGuard and role ports even for a scoped UDP deny', function (string $port): void {
    $this->denyNode->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);

    denyHttp(['port' => $port, 'protocol' => 'udp', 'source' => '192.0.2.1'])
        ->assertStatus(422)->assertJsonPath('error.code', 'firewall.protected_port');
    expect(Operation::query()->count())->toBe(0)->and($this->denyNode->firewallRules()->count())->toBe(0)
        ->and($this->socket->frames)->toBe([]);
})->with(['22', '21:23', '2222', '2221:2223', '51820', '51819:51821', '443', '442:444']);

it('refuses normalized conflicts and both-protocol overlaps without touching the existing rule', function (string $action, string $protocol, array $input, string $code): void {
    $existing = $this->denyNode->firewallRules()->create([
        'action' => $action, 'port' => '8080', 'protocol' => $protocol, 'source' => '192.0.2.0/24', 'status' => 'active',
    ]);
    $before = $existing->refresh()->getAttributes();

    denyHttp(['port' => '8080', 'source' => '192.0.2.67/24', ...$input])
        ->assertStatus(409)->assertJsonPath('error.code', $code);
    expect($existing->refresh()->getAttributes())->toBe($before)
        ->and($this->denyNode->firewallRules()->count())->toBe(1)
        ->and(Operation::query()->count())->toBe(0)->and($this->socket->frames)->toBe([]);
})->with([
    'opposite action' => ['allow', 'tcp', ['protocol' => 'tcp'], 'firewall.rule_conflict'],
    'both then single' => ['deny', 'any', ['protocol' => 'tcp'], 'firewall.rule_overlap'],
    'single then both' => ['deny', 'udp', [], 'firewall.rule_overlap'],
    'opposite action overlap' => ['allow', 'tcp', [], 'firewall.rule_overlap'],
]);

it('serializes all firewall writers on the Node and rolls back a busy admission', function (): void {
    denyHttp(['port' => '8080'])->assertStatus(202);
    denyHttp(['port' => '8081'])->assertStatus(409)->assertJsonPath('error.code', 'operation.target_busy');

    expect(Operation::query()->count())->toBe(1)->and($this->denyNode->firewallRules()->count())->toBe(1)
        ->and($this->socket->frames)->toHaveCount(1);
});

it('reuses active, failed and interrupted deny identity with a new Operation and updated label', function (string $status): void {
    denyHttp(['port' => '8080', 'label' => 'first'])->assertStatus(202);
    $first = Operation::query()->sole();
    denyHttpComplete($first, $status);
    expect($this->denyNode->firewallRules()->sole()->status->value)->toBe($status === 'succeeded' ? 'active' : 'failed');

    denyHttp(['port' => '8080', 'label' => 'new label'])->assertStatus(202);
    $second = Operation::query()->latest('id')->firstOrFail();
    expect($second->id)->not->toBe($first->id)
        ->and($second->input['rule_id'])->toBe($first->input['rule_id'])
        ->and($this->denyNode->firewallRules()->count())->toBe(1)
        ->and($this->denyNode->firewallRules()->sole()->label)->toBe('new label')
        ->and($this->socket->frames)->toHaveCount(2);
    denyHttpComplete($second, 'succeeded');
    expect($this->denyNode->firewallRules()->sole()->status->value)->toBe('active');
})->with(['succeeded', 'failed', 'interrupted']);

it('permits a narrower deny beside a broader allow', function (): void {
    $this->denyNode->firewallRules()->create([
        'action' => 'allow', 'port' => '8080', 'protocol' => 'any', 'source' => 'any', 'status' => 'active',
    ]);

    denyHttp(['port' => '8080', 'source' => '192.0.2.1'])->assertStatus(202);
    expect($this->denyNode->firewallRules()->count())->toBe(2)->and($this->socket->frames)->toHaveCount(1);
});
