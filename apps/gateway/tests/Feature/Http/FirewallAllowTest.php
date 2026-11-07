<?php

declare(strict_types=1);

use App\Domain\Firewall\FirewallInspectionShape;
use App\Domain\Firewall\FirewallInspectionTarget;
use App\Domain\Firewall\FirewallIntentProjection;
use App\Domain\Metrics\MetricsFirewallExpectationProvider;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampConnection;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

afterEach(fn () => app(SwampConnection::class)->release());

beforeEach(function (): void {
    Queue::fake();
    config(['swamp.run_token' => 'test-token', 'swamp.read_token' => 'read-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
    $this->starts = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $message): void {
        $this->starts[] = $message;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function (): array {
        $request = end($this->starts);
        if (in_array($request['type'], ['workflow.history.get', 'workflow.run.search'], true)) {
            return [['type' => $request['type'], 'id' => $request['id'], 'payload' => ['data' => $request['type'] === 'workflow.history.get' ? $this->history : ['results' => []]]]];
        }

        return [[
            'type' => 'event', 'id' => $request['id'],
            'event' => ['kind' => 'started', 'workflowName' => 'operation-firewall-direct', 'runId' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]];
    });
    $this->app->instance(WebSocketClient::class, $socket);
    $this->caller = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'operator', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit',
    ]));
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'app-dev', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'target-public-key',
    ]));
    $this->caller->roles()->create(['role' => RoleName::Swamp, 'status' => LifecycleStatus::Active]);
    $this->caller->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->url = '/api/v1/nodes/'.$this->node->id.'/firewall-rules/allow';
});

it('captures normalized allow intent and pinned SSH without contacting the Node at admission', function (): void {
    $response = $this->postJson($this->url, ['port' => '08000:08100', 'source' => '192.0.2.99/24', 'label' => "office 'web'\n"])
        ->assertAccepted()->assertJsonPath('data.status', 'running');
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $rule = FirewallRule::query()->sole();
    $intent = app(FirewallIntentProjection::class)->input($operation);

    expect($this->starts)->toHaveCount(1)
        ->and($this->starts[0]['payload']['workflowIdOrName'])->toBe('operation-firewall-direct')
        ->and($this->starts[0]['payload']['inputs']['intent'])->toBe($intent['intent'])
        ->and($rule->port)->toBe('8000:8100')->and($rule->source)->toBe('192.0.2.0/24')
        ->and($rule->protocol)->toBe('any')->and($rule->status)->toBe(LifecycleStatus::Provisioning)
        ->and($operation->lock_key)->toBe(OperationStore::lockKey('node_firewall', $this->node->id))
        ->and($intent['intent']['rule'])->toMatchArray([
            'node_id' => $this->node->id, 'action' => 'allow', 'port' => '8000:8100', 'protocol' => 'any',
            'source' => '192.0.2.0/24', 'comment' => 'orbit office web',
        ])
        ->and($intent['intent']['ssh']['target'])->toMatchArray([
            'host' => '10.44.0.2', 'user' => 'orbit', 'host_keys' => ['ssh-ed25519 target-public-key'],
        ])
        ->and($intent['intent']['ensure'])->toBe('present')
        ->and($intent['execution_secrets'])->toBe([]);
});

it('defaults to both protocols and both families and bounds the printable UFW comment', function (): void {
    $this->postJson($this->url, ['port' => '443', 'label' => str_repeat('a', 255)])->assertAccepted();
    $operation = Operation::query()->sole();

    expect($operation->input['firewall']['rule'])->toMatchArray([
        'protocol' => 'any', 'source' => 'any', 'comment' => 'orbit '.str_repeat('a', 64),
    ])->and(FirewallRule::query()->sole()->label)->toBe(str_repeat('a', 255));
});

it('refuses invalid allow input without recording a rule or Operation', function (array $input): void {
    $this->postJson($this->url, $input)->assertUnprocessable();

    expect(FirewallRule::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0);
})->with([
    'missing port' => [[]],
    'service name' => [['port' => 'https']],
    'zero port' => [['port' => '0']],
    'high port' => [['port' => '65536']],
    'reversed range' => [['port' => '8100:8000']],
    'empty source' => [['port' => '443', 'source' => '']],
    'bad IPv4' => [['port' => '443', 'source' => '192.0.2.999']],
    'bad IPv6 CIDR' => [['port' => '443', 'source' => '2001:db8::/129']],
    'explicit any protocol' => [['port' => '443', 'protocol' => 'any']],
    'long label' => [['port' => '443', 'label' => str_repeat('a', 256)]],
]);

it('requires access even for a protected-access skip', function (): void {
    $this->caller->accessibleNodes()->detach();
    $this->postJson($this->url, ['port' => '22'])->assertForbidden()->assertJsonPath('error.code', 'node_access.required');

    expect(FirewallRule::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0);
});

it('requires Linux even for a protected-access skip', function (): void {
    $this->node->update(['platform' => 'darwin']);
    $this->postJson($this->url, ['port' => '22'])->assertUnprocessable()->assertJsonPath('error.code', 'firewall.platform_unsupported');

    expect(FirewallRule::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0);
});

it('skips protected access without needing SSH target material or creating an operator rule', function (): void {
    Node::withoutEvents(fn () => $this->node->update(['ssh_host_key' => null]));
    $this->postJson($this->url, ['port' => '22:22', 'source' => '2001:db8::1/128', 'protocol' => 'udp'])
        ->assertAccepted()->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.result.skipped', 'firewall.rule_exists');
    $operation = Operation::query()->sole();

    expect($this->starts)->toBe([])->and(FirewallRule::query()->count())->toBe(0)->and($operation->lock_key)->toBeNull()
        ->and($operation->state['swamp_run_id'] ?? null)->toBeNull();
});

it('honors the per-Node lock for protected skips and normal allows without changing intent', function (string $port): void {
    app(OperationStore::class)->create('firewall.deny', 'node_firewall', $this->node->id, $this->node->id, [], 'operator', null,
        OperationStore::lockKey('node_firewall', $this->node->id));
    $this->postJson($this->url, ['port' => $port])->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');

    expect(FirewallRule::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(1);
})->with(['22', '443']);

it('refuses the opposite identity or a both-protocol overlap regardless of action', function (string $action, string $existingProtocol, ?string $protocol, string $code): void {
    $this->node->firewallRules()->create([
        'action' => $action, 'port' => '5432', 'protocol' => $existingProtocol, 'source' => '192.0.2.0/24', 'status' => LifecycleStatus::Active,
    ]);
    $this->postJson($this->url, array_filter(['port' => '5432', 'source' => '192.0.2.99/24', 'protocol' => $protocol]))
        ->assertConflict()->assertJsonPath('error.code', $code);

    expect(FirewallRule::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(0)
        ->and(FirewallRule::query()->sole()->status)->toBe(LifecycleStatus::Active);
})->with([
    'opposite tuple' => ['deny', 'tcp', 'tcp', 'firewall.rule_conflict'],
    'allow both to single' => ['allow', 'any', 'tcp', 'firewall.rule_overlap'],
    'deny both to single' => ['deny', 'any', 'udp', 'firewall.rule_overlap'],
    'allow single to both' => ['allow', 'tcp', null, 'firewall.rule_overlap'],
    'deny single to both' => ['deny', 'udp', null, 'firewall.rule_overlap'],
]);

it('admits an overlapping source scope as its own identity', function (): void {
    $this->node->firewallRules()->create([
        'action' => 'deny', 'port' => '5432', 'protocol' => 'any', 'source' => 'any', 'status' => LifecycleStatus::Active,
    ]);
    $this->postJson($this->url, ['port' => '5432', 'source' => '192.0.2.1', 'protocol' => 'tcp'])->assertAccepted();

    expect(FirewallRule::query()->count())->toBe(2)->and(Operation::query()->count())->toBe(1);
});

it('reuses a failed or active identity in a new Operation and records a changed label', function (LifecycleStatus $status): void {
    $rule = $this->node->firewallRules()->create([
        'action' => 'allow', 'port' => '5432', 'protocol' => 'tcp', 'source' => '2001:db8::1',
        'status' => $status, 'label' => 'old', 'error_code' => 'firewall.verify_failed',
    ]);
    $this->postJson($this->url, ['port' => '5432', 'source' => '2001:0db8::1/128', 'protocol' => 'tcp', 'label' => 'new'])->assertAccepted();

    expect(FirewallRule::query()->count())->toBe(1)->and($rule->refresh()->label)->toBe('new')
        ->and($rule->status)->toBe(LifecycleStatus::Provisioning)->and($rule->error_code)->toBeNull()
        ->and(Operation::query()->sole()->input['rule_id'])->toBe($rule->id);
})->with([LifecycleStatus::Failed, LifecycleStatus::Active]);

it('replays a keyed allow without a second Operation and refuses changed keyed input', function (): void {
    $response = $this->postJson($this->url, ['port' => '443'], ['Idempotency-Key' => 'allow-retry'])->assertAccepted();
    $this->postJson($this->url, ['port' => '443'], ['Idempotency-Key' => 'allow-retry'])
        ->assertAccepted()->assertJsonPath('data.id', $response->json('data.id'));
    $this->postJson($this->url, ['port' => '444'], ['Idempotency-Key' => 'allow-retry'])
        ->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');

    expect(FirewallRule::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(1)
        ->and($this->starts)->toHaveCount(1);
});

it('replays a keyed protected allow as the same succeeded Operation without a native run', function (): void {
    $headers = ['Idempotency-Key' => 'protected-allow-retry'];
    $response = $this->postJson($this->url, ['port' => '22'], $headers)
        ->assertAccepted()->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.result.skipped', 'firewall.rule_exists');
    $this->postJson($this->url, ['port' => '22'], $headers)
        ->assertAccepted()->assertJsonPath('data.id', $response->json('data.id'))
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.result.skipped', 'firewall.rule_exists');

    expect(Operation::query()->count())->toBe(1)->and(FirewallRule::query()->count())->toBe(0)
        ->and(Operation::query()->sole()->status)->toBe(OperationStatus::Succeeded)
        ->and($this->starts)->toBe([]);
});

it('skips a source contained by one protected range but admits a wider range or source as an operator rule', function (string $port, string $source, bool $skipped, string $protected = '192.0.2.0/24'): void {
    $target = new FirewallInspectionTarget($this->node, new FirewallInspectionShape(
        comment: 'orbit:test-metrics', action: 'allow', direction: 'in', source: $protected, destination: 'any',
        port: '8000:8100', protocol: 'tcp', inInterface: null, outInterface: null, family: str_contains($protected, ':') ? 'v6' : 'v4',
    ), 'metrics', 'metrics');
    $this->mock(MetricsFirewallExpectationProvider::class)->shouldReceive('for')->andReturn([$target]);
    $response = $this->postJson($this->url, ['port' => $port, 'source' => $source, 'protocol' => 'tcp'])->assertAccepted();

    expect($response->json('data.status'))->toBe($skipped ? 'succeeded' : 'running')
        ->and($response->json('data.result.skipped'))->toBe($skipped ? 'firewall.rule_exists' : null)
        ->and(FirewallRule::query()->count())->toBe($skipped ? 0 : 1)
        ->and($this->starts)->toHaveCount($skipped ? 0 : 1);
})->with([
    'admitted address' => ['8001', '192.0.2.5', true],
    'admitted subnet' => ['8001:8050', '192.0.2.128/25', true],
    'beyond protected ports' => ['8000:8101', '192.0.2.5', false],
    'broader source' => ['8001', '192.0.0.0/16', false],
    'all sources' => ['8001', 'any', false],
    'other family' => ['8001', '2001:db8::1', false],
    'admitted IPv6 address' => ['8001', '2001:db8::1', true, '2001:db8::/65'],
    'admitted IPv6 subnet' => ['8001', '2001:db8::/80', true, '2001:db8::/65'],
    'non-admitted IPv6 address' => ['8001', '2001:db8:0:0:8000::1', false, '2001:db8::/65'],
    'all IPv4 admits IPv4' => ['8001', '192.0.2.1', true, '0.0.0.0/0'],
    'all IPv4 does not admit any' => ['8001', 'any', false, '0.0.0.0/0'],
    'all IPv6 admits IPv6' => ['8001', '2001:db8::1', true, '::/0'],
    'all IPv6 does not admit IPv4' => ['8001', '192.0.2.1', false, '::/0'],
]);

it('retains the both-protocol failed record and native error when the second UFW allow fails before verification', function (): void {
    $response = $this->postJson($this->url, ['port' => '47443', 'source' => '192.0.2.1'])->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $rule = FirewallRule::query()->sole();
    $run = $operation->state['swamp_run_id'];
    $this->history = [
        'id' => $run, 'workflowName' => 'operation-firewall-direct', 'status' => 'failed',
        'startedAt' => now()->subSecond()->toIso8601String(), 'completedAt' => now()->toIso8601String(),
        'inputs' => $this->starts[0]['payload']['inputs'],
        'jobs' => [['name' => 'firewall', 'steps' => [
            ['name' => 'Read the rules', 'status' => 'succeeded'],
            ['name' => 'Plan the changes', 'status' => 'succeeded'],
            ['name' => 'Change the rules-0', 'status' => 'succeeded', 'outputs' => ['exitCode' => 0]],
            ['name' => 'Change the rules-1', 'status' => 'failed', 'outputs' => ['exitCode' => 1, 'stderr' => 'The second UFW allow failed.']],
            ['name' => 'Read the result', 'status' => 'skipped'],
            ['name' => 'Check the rules', 'status' => 'skipped'],
            ['name' => 'accepted', 'status' => 'skipped'],
        ]]],
    ];
    $selector = app(DirectOperation::class)->definition('firewall.allow')['terminal_result'];
    app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $run, 'status' => 'failed'], $selector);

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('operation.native_failed')
        ->and($operation->failed_step)->toBe('Change the rules-1')
        ->and($operation->lock_key)->toBeNull()
        ->and($rule->refresh()->status)->toBe(LifecycleStatus::Failed)
        ->and($rule->error_code)->toBe('operation.native_failed')
        ->and($rule->protocol)->toBe('any');

    $this->postJson($this->url, ['port' => '47443', 'source' => '192.0.2.1'])->assertAccepted();
    expect(FirewallRule::query()->sole()->id)->toBe($rule->id);
});

it('projects the native allow result, retains failed intent, and releases the lock exactly once', function (string $status, ?string $code, array $result): void {
    $this->postJson($this->url, ['port' => '443'])->assertAccepted();
    $operation = Operation::query()->sole();
    $data = [
        'run_id' => $operation->state['swamp_run_id'], 'status' => $status, 'error_code' => $code,
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => $result + ['raw' => 'must not be public'],
        'failure' => $code === null ? null : ['failed_step' => 'Check the rules'],
    ];
    $projection = app(FirewallIntentProjection::class);
    $projection->apply($operation, $data);
    $projection->apply($operation, $data);
    $rule = FirewallRule::query()->sole();

    expect($operation->refresh()->status)->toBe(OperationStatus::from($status))
        ->and($operation->result)->toBe($result)->and($operation->error_code)->toBe($code)
        ->and($operation->lock_key)->toBeNull()->and($operation->finished_at)->not->toBeNull()
        ->and($rule->status)->toBe($status === 'succeeded' ? LifecycleStatus::Active : LifecycleStatus::Failed)
        ->and($rule->error_code)->toBe($code);
    $this->postJson($this->url, ['port' => '443', 'label' => 'retry'])->assertAccepted();
    expect(FirewallRule::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(2)
        ->and($this->starts)->toHaveCount(2);
})->with([
    'applied' => ['succeeded', null, ['backend' => 'active']],
    'already correct' => ['succeeded', null, ['backend' => 'active', 'skipped' => 'firewall.rule_exists']],
    'inactive' => ['failed', 'firewall.backend_inactive', ['error_code' => 'firewall.backend_inactive']],
    'missing or misplaced' => ['failed', 'firewall.verify_failed', ['error_code' => 'firewall.verify_failed']],
    'native transport or apply failed' => ['failed', 'operation.native_failed', []],
]);
