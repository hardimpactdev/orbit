<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Convergence\ConvergenceStatus;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Convergence\UfwFirewallRule;
use App\Services\Firewall\RemoteFirewallRule;
use App\Services\Firewall\RemoteFirewallRuleProbe;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteLocalExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(
        RemoteFirewallRuleProbe::class,
        new RemoteFirewallRuleProbe(app(RemoteLocalExecutor::class)),
    );
});

it('plans ok when the remote ufw rule already matches gateway intent', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.91:9477/v1/commands' => Http::response(ufw_firewall_rule_probe_agent_payload(<<<'OUT'
            Status: active

                 To                         Action      From
                 --                         ------      ----
            [ 1] 5173/tcp                   ALLOW IN    10.6.0.0/24

            OUT)),
    ]);
    app()->instance(RemoteFirewallRule::class, ufw_firewall_rule_remote());
    $node = ufw_firewall_rule_node('10.44.0.91');
    $rule = ufw_firewall_rule($node, [
        'name' => 'local-vite',
        'source' => '10.6.0.0/24',
        'port' => '5173',
        'protocol' => 'tcp',
    ]);
    $convergence = UfwFirewallRule::fromRule($rule);
    $shell = new UfwFirewallRuleUnusedShell;

    $probe = $convergence->probe($node);
    $plan = $convergence->plan($probe);
    $result = $convergence->apply($node, $plan);

    expect($probe->reachable)
        ->toBeTrue()
        ->and($probe->present)
        ->toBeTrue()
        ->and($plan->status)
        ->toBe(ConvergenceStatus::Ok)
        ->and($result->status)
        ->toBe(ConvergenceStatus::Ok)
        ->and($result->changed())
        ->toBeFalse();

    expect(ufw_firewall_rule_agent_requests())->toBeEmpty();
});

it('applies a missing ufw rule through the agent-push local executor', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.92:9477/v1/commands' => Http::sequence()
            ->push(ufw_firewall_rule_probe_agent_payload(
                "Status: active\n\nTo                         Action      From\n--                         ------      ----\n",
            ))
            ->push(ufw_firewall_rule_agent_payload('apply')),
    ]);
    app()->instance(RemoteFirewallRule::class, ufw_firewall_rule_remote());
    $node = ufw_firewall_rule_node('10.44.0.92', 'app-1');
    $rule = ufw_firewall_rule($node, [
        'name' => 'local-vite',
        'source' => '10.6.0.0/24',
        'port' => '5173',
        'reason' => 'local development',
    ]);
    $convergence = UfwFirewallRule::fromRule($rule);
    $shell = new UfwFirewallRuleUnusedShell;

    $probe = $convergence->probe($node);
    $plan = $convergence->plan($probe);
    $result = $convergence->apply($node, $plan);

    expect($plan->status)
        ->toBe(ConvergenceStatus::Changed)
        ->and($plan->summary)
        ->toContain('local-vite')
        ->and($result->status)
        ->toBe(ConvergenceStatus::Changed)
        ->and($result->changed())
        ->toBeTrue();

    $requests = ufw_firewall_rule_agent_requests();

    expect($requests)
        ->toHaveCount(1)
        ->and($requests[0]['action'])
        ->toBe('apply')
        ->and($requests[0]['shape'])
        ->toMatchArray([
            'direction' => 'incoming',
            'action' => 'allow',
            'source' => '10.6.0.0/24',
            'destination' => null,
            'port' => '5173',
            'protocol' => 'tcp',
            'address_family' => 'v4',
            'interface' => null,
            'reason' => 'local development',
        ]);
});

it('deletes a partial match before re-applying gateway intent', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.93:9477/v1/commands' => Http::sequence()
            ->push(ufw_firewall_rule_probe_agent_payload(<<<'OUT'
                Status: active

                     To                         Action      From
                     --                         ------      ----
                [ 1] 5173/tcp                   ALLOW IN    Anywhere

                OUT))
            ->push(ufw_firewall_rule_agent_payload('delete'))
            ->push(ufw_firewall_rule_agent_payload('apply')),
    ]);
    app()->instance(RemoteFirewallRule::class, ufw_firewall_rule_remote());
    $node = ufw_firewall_rule_node('10.44.0.93');
    $rule = ufw_firewall_rule($node, [
        'name' => 'local-vite',
        'source' => '10.6.0.0/24',
        'port' => '5173',
    ]);
    $convergence = UfwFirewallRule::fromRule($rule);
    $shell = new UfwFirewallRuleUnusedShell;

    $probe = $convergence->probe($node);
    $plan = $convergence->plan($probe);
    $result = $convergence->apply($node, $plan);

    expect($probe->present)
        ->toBeFalse()
        ->and($probe->partialMatch)
        ->not
        ->toBeNull()
        ->and($plan->status)
        ->toBe(ConvergenceStatus::Changed)
        ->and($result->changed())
        ->toBeTrue();

    $requests = ufw_firewall_rule_agent_requests();

    expect(array_column($requests, 'action'))
        ->toBe(['delete', 'apply'])
        ->and($requests[0]['shape'])
        ->toMatchArray([
            'direction' => 'incoming',
            'action' => 'allow',
            'source' => 'any',
            'destination' => null,
            'port' => '5173',
            'protocol' => 'tcp',
        ])
        ->and($requests[1]['shape']['source'])
        ->toBe('10.6.0.0/24');
});

it('canonicalizes host CIDR and both family in expected shape for convergence', function (): void {
    $node = ufw_firewall_rule_node('10.44.0.95');
    $rule = ufw_firewall_rule($node, [
        'name' => 'main1-valkey',
        'source' => '10.6.0.13/32',
        'port' => '6379',
        'protocol' => 'tcp',
        'address_family' => 'both',
    ]);

    expect(UfwFirewallRule::fromRule($rule)->expectedShape())
        ->toMatchArray([
            'source' => '10.6.0.13',
            'destination' => null,
            'port' => '6379',
            'protocol' => 'tcp',
            'address_family' => 'v4',
        ]);
});

it('plans ok when observed bare host IP matches host CIDR intent with both family', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.96:9477/v1/commands' => Http::response(ufw_firewall_rule_probe_agent_payload(<<<'OUT'
            Status: active

                 To                         Action      From
                 --                         ------      ----
            [ 1] 6379/tcp                   ALLOW IN    10.6.0.13

            OUT)),
    ]);
    app()->instance(RemoteFirewallRule::class, ufw_firewall_rule_remote());
    $node = ufw_firewall_rule_node('10.44.0.96');
    $rule = ufw_firewall_rule($node, [
        'name' => 'main1-valkey',
        'source' => '10.6.0.13/32',
        'port' => '6379',
        'protocol' => 'tcp',
        'address_family' => 'both',
    ]);
    $convergence = UfwFirewallRule::fromRule($rule);
    $shell = new UfwFirewallRuleUnusedShell;

    $probe = $convergence->probe($node);
    $plan = $convergence->plan($probe);

    expect($probe->reachable)
        ->toBeTrue()
        ->and($probe->present)
        ->toBeTrue()
        ->and($plan->status)
        ->toBe(ConvergenceStatus::Ok);
});

it('plans ok when observed CIDR matches intent stored with both family', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.97:9477/v1/commands' => Http::response(ufw_firewall_rule_probe_agent_payload(<<<'OUT'
            Status: active

                 To                         Action      From
                 --                         ------      ----
            [ 1] 5173/tcp                   ALLOW IN    10.6.0.0/24

            OUT)),
    ]);
    app()->instance(RemoteFirewallRule::class, ufw_firewall_rule_remote());
    $node = ufw_firewall_rule_node('10.44.0.97');
    $rule = ufw_firewall_rule($node, [
        'name' => 'private-service',
        'source' => '10.6.0.0/24',
        'port' => '5173',
        'address_family' => 'both',
    ]);
    $convergence = UfwFirewallRule::fromRule($rule);

    $probe = $convergence->probe($node);
    $plan = $convergence->plan($probe);

    expect($probe->present)
        ->toBeTrue()
        ->and($plan->status)
        ->toBe(ConvergenceStatus::Ok);
});

it('requires both concrete UFW entries for wildcard both intent', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.98:9477/v1/commands' => Http::response(ufw_firewall_rule_probe_agent_payload(<<<'OUT'
            Status: active

                 To                         Action      From
                 --                         ------      ----
            [ 1] 8443/tcp                   ALLOW IN    Anywhere
            [ 2] 8443/tcp (v6)              ALLOW IN    Anywhere (v6)

            OUT)),
    ]);
    app()->instance(RemoteFirewallRule::class, ufw_firewall_rule_remote());
    $node = ufw_firewall_rule_node('10.44.0.98');
    $rule = ufw_firewall_rule($node, [
        'name' => 'public-service',
        'source' => 'any',
        'port' => '8443',
        'address_family' => 'both',
    ]);
    $convergence = UfwFirewallRule::fromRule($rule);

    $probe = $convergence->probe($node);
    $plan = $convergence->plan($probe);

    expect($probe->present)
        ->toBeTrue()
        ->and($plan->status)
        ->toBe(ConvergenceStatus::Ok);
});

it('applies wildcard both intent without deleting its matching IPv4 entry when IPv6 is missing', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.99:9477/v1/commands' => Http::sequence()
            ->push(ufw_firewall_rule_probe_agent_payload(<<<'OUT'
                Status: active

                     To                         Action      From
                     --                         ------      ----
                [ 1] 8443/tcp                   ALLOW IN    Anywhere

                OUT))
            ->push(ufw_firewall_rule_agent_payload('apply')),
    ]);
    app()->instance(RemoteFirewallRule::class, ufw_firewall_rule_remote());
    $node = ufw_firewall_rule_node('10.44.0.99');
    $rule = ufw_firewall_rule($node, [
        'name' => 'public-service',
        'source' => 'any',
        'port' => '8443',
        'address_family' => 'both',
    ]);
    $convergence = UfwFirewallRule::fromRule($rule);

    $probe = $convergence->probe($node);
    $plan = $convergence->plan($probe);
    $result = $convergence->apply($node, $plan);

    expect($probe->present)
        ->toBeFalse()
        ->and($probe->partialMatch)
        ->toBeNull()
        ->and($plan->status)
        ->toBe(ConvergenceStatus::Changed)
        ->and($result->changed())
        ->toBeTrue()
        ->and(array_column(ufw_firewall_rule_agent_requests(), 'action'))
        ->toBe(['apply']);
});

it('reports unreachable when ufw introspection fails', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.94:9477/v1/commands' => Http::response([
            'transport' => 'agent-push',
            'operation_id' => 'firewall.rule.probe',
            'binary' => 'orbit',
            'status' => 'failed',
            'exit_code' => 1,
            'frames' => [
                [
                    'type' => 'stderr',
                    'message' => 'ufw unavailable',
                ],
                [
                    'type' => 'exit',
                    'message' => '1',
                ],
            ],
        ]),
    ]);
    app()->instance(RemoteFirewallRule::class, ufw_firewall_rule_remote());
    $node = ufw_firewall_rule_node('10.44.0.94');
    $rule = ufw_firewall_rule($node);
    $convergence = UfwFirewallRule::fromRule($rule);
    $shell = new UfwFirewallRuleUnusedShell;

    $probe = $convergence->probe($node);
    $plan = $convergence->plan($probe);

    expect($probe->reachable)
        ->toBeFalse()
        ->and($probe->error)
        ->toBe('ufw unavailable')
        ->and($plan->status)
        ->toBe(ConvergenceStatus::Unreachable);
});

/**
 * @return array<string, mixed>
 */
function ufw_firewall_rule_probe_agent_payload(string $output): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => 'firewall.rule.probe',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => [
                            'output' => $output,
                        ],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => '0',
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function ufw_firewall_rule_agent_payload(string $action): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => "firewall.rule.{$action}",
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => [
                            'action' => $action,
                            'changed' => true,
                        ],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => '0',
            ],
        ],
    ];
}

function ufw_firewall_rule_node(string $wireguardAddress, string $name = 'app-1'): Node
{
    $node = Node::factory()->create([
        'name' => $name,
        'tld' => 'test',
        'platform' => 'ubuntu',
        'wireguard_address' => $wireguardAddress,
        'managed' => true,
    ]);

    if (! $node instanceof Node) {
        throw new RuntimeException('Node factory did not return a node model.');
    }

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Active,
        'settings' => ['tld' => 'test'],
    ]);

    return $node;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ufw_firewall_rule(Node $node, array $attributes = []): FirewallRule
{
    $rule = FirewallRule::factory()->create([
        'node_id' => $node->id,
        ...$attributes,
    ]);

    if (! $rule instanceof FirewallRule) {
        throw new RuntimeException('Firewall rule factory did not return a firewall rule model.');
    }

    return $rule;
}

function ufw_firewall_rule_remote(): RemoteFirewallRule
{
    return new RemoteFirewallRule(new RemoteLocalExecutor(
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: ufw_firewall_rule_operation_secret(),
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        outputRedactor: app(\App\Services\RemoteShell\RemoteExecutorOutputRedactor::class),
        agentPush: app(\App\Services\NodeCommandTransport\NodeAgentPushDispatcher::class),
        gatewayLocal: app(\App\Services\RemoteShell\GatewayLocalCommandDispatcher::class),
        applicationKey: ufw_firewall_rule_operation_secret(),
    ));
}

/**
 * @return list<array{action: string, shape: array<string, mixed>}>
 */
function ufw_firewall_rule_agent_requests(): array
{
    $requests = [];

    foreach (Http::recorded() as $record) {
        $candidate = is_array($record) ? $record[0] ?? null : null;

        if (! $candidate instanceof Request) {
            continue;
        }

        $request = $candidate;
        $argv = $request['argv'];

        if (($argv[0] ?? null) !== 'internal:firewall-rule') {
            continue;
        }

        $input = json_decode((string) $request['input'], associative: true, flags: JSON_THROW_ON_ERROR);

        expect($request['binary'])
            ->toBe('orbit')
            ->and($argv[0] ?? null)
            ->toBe('internal:firewall-rule')
            ->and(str_starts_with((string) ($argv[1] ?? ''), '--operation-token='))
            ->toBeTrue()
            ->and($argv[2] ?? null)
            ->toBe('--json')
            ->and($request['stream'])
            ->toBeTrue();

        if (! is_array($input) || ! is_string($input['action'] ?? null) || ! is_array($input['shape'] ?? null)) {
            continue;
        }

        /** @var array<string, mixed> $shape */
        $shape = $input['shape'];

        $requests[] = [
            'action' => $input['action'],
            'shape' => $shape,
        ];
    }

    return $requests;
}

function ufw_firewall_rule_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

final class UfwFirewallRuleUnusedShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        throw new RuntimeException('Remote shell should not be called for UFW firewall rule convergence.');
    }
}

final class UfwFirewallRuleUnusedTransport implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        throw new RuntimeException('SSH transport should not be called for UFW firewall rule convergence.');
    }
}
