<?php

declare(strict_types=1);

use App\Data\Doctor\ProbeSnapshot;
use App\Enums\AdoptAction;
use App\Enums\DriftKind;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Firewall\FirewallRuleProbe;
use App\Services\Firewall\FirewallRuleShapeCanonicalizer;
use App\Services\Firewall\RemoteFirewallRuleProbe;
use App\Services\RemoteShell\RemoteLocalExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(
        RemoteFirewallRuleProbe::class,
        new RemoteFirewallRuleProbe(app(RemoteLocalExecutor::class)),
    );
});

function firewallProbeIssue(array $drift, string $key): mixed
{
    return collect($drift)->first(fn ($entry): bool => $entry->key === $key);
}

function createFirewallRuleProbeAppHostNode(array $attributes = []): Node
{
    $node = Node::factory()->create([
        'status' => 'active',
        'platform' => 'ubuntu',
        ...$attributes,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
        'settings' => ['tld' => 'test'],
    ]);

    return $node;
}

function createFirewallRuleProbeGatewayAssignmentNode(array $attributes = []): Node
{
    $node = Node::factory()->create([
        'status' => 'active',
        'platform' => 'ubuntu',
        ...$attributes,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
        'settings' => [],
    ]);

    return $node;
}

function firewall_rule_probe_agent_response(string $output): mixed
{
    return Http::response([
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
    ]);
}

function firewall_rule_probe_request_matches(Request $request): bool
{
    return (
        $request->url() === 'http://10.44.0.81:9477/v1/commands'
        && $request['binary'] === 'orbit'
        && agentPushRequestOperationIdMatchesToken($request)
        && $request['timeout_seconds'] === 15
        && $request['argv'][0] === 'internal:firewall-rule:probe'
        && str_starts_with((string) $request['argv'][1], '--operation-token=')
        && $request['argv'][2] === '--json'
    );
}

describe('FirewallRuleProbe interface', function (): void {
    it('has key and label', function (): void {
        $probe = new FirewallRuleProbe;

        expect($probe->key())->toBe('firewall_rule')->and($probe->label())->toBe('Firewall rules');
    });

    it('returns an empty snapshot when no target node is available', function (): void {
        $rule = new FirewallRule(['name' => 'local-vite']);

        expect(
            new FirewallRuleProbe()
                ->introspect($rule)
                ->isEmpty(),
        )->toBeTrue();
    });
});

describe('firewall backend UFW reality', function (): void {
    it('introspects UFW rules through the node agent transport', function (): void {
        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.81:9477/v1/commands' => firewall_rule_probe_agent_response(<<<'UFW'
                Status: active

                     To                         Action      From
                     --                         ------      ----
                [ 1] 5173/tcp                   ALLOW IN    10.6.0.0/24
                [ 2] 5173/tcp (v6)              ALLOW IN    Anywhere (v6)
                UFW),
        ]);
        $node = createFirewallRuleProbeAppHostNode([
            'managed' => true,
            'wireguard_address' => '10.44.0.81',
        ]);
        $rule = FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'local-vite']);

        $snapshot = new FirewallRuleProbe()->introspect($rule);

        expect($snapshot->get('incoming:allow:10.6.0.0/24:any:5173:tcp:v4:any'))
            ->toMatchArray([
                'direction' => 'incoming',
                'action' => 'allow',
                'source' => '10.6.0.0/24',
                'port' => '5173',
                'protocol' => 'tcp',
            ]);

        Http::assertSent(fn (Request $request): bool => firewall_rule_probe_request_matches($request));
    });

    it('detects missing backend rules after UFW inspection', function (): void {
        $node = createFirewallRuleProbeAppHostNode();
        $rule = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'local-vite',
            'source' => '10.6.0.0/24',
            'port' => '5173',
        ]);
        $snapshot = new FirewallRuleProbe()->snapshotFromUfwOutput(<<<'UFW'
            Status: active

                 To                         Action      From
                 --                         ------      ----
            UFW);

        $drift = new FirewallRuleProbe()->diff($rule, $snapshot);

        expect(firewallProbeIssue($drift, 'firewall_rule.rule_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects backend rule shape mismatches', function (): void {
        $node = createFirewallRuleProbeAppHostNode();
        $rule = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'local-vite',
            'source' => '10.6.0.0/24',
            'port' => '5173',
        ]);
        $snapshot = new FirewallRuleProbe()->snapshotFromUfwOutput(<<<'UFW'
            Status: active

                 To                         Action      From
                 --                         ------      ----
            [ 1] 5173/tcp                   ALLOW IN    Anywhere
            UFW);

        $drift = new FirewallRuleProbe()->diff($rule, $snapshot);
        $issue = firewallProbeIssue($drift, 'firewall_rule.rule_mismatch');

        expect($issue?->kind)
            ->toBe(DriftKind::Divergent)
            ->and($issue?->detail['expected']['source'] ?? null)
            ->toBe('10.6.0.0/24')
            ->and($issue?->detail['observed']['source'] ?? null)
            ->toBe('any');
    });

    it('passes when backend rule shape matches gateway intent', function (): void {
        $node = createFirewallRuleProbeAppHostNode();
        $rule = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'local-vite',
            'source' => '10.6.0.0/24',
            'port' => '5173',
        ]);
        $snapshot = new FirewallRuleProbe()->snapshotFromUfwOutput(<<<'UFW'
            Status: active

                 To                         Action      From
                 --                         ------      ----
            [ 1] 5173/tcp                   ALLOW IN    10.6.0.0/24
            UFW);

        $drift = new FirewallRuleProbe()->diff($rule, $snapshot);

        expect($drift)->toBe([]);
    });

    it('derives the concrete family from IPv4 and IPv6 CIDR intent stored as both', function (
        string $source,
        string $observedRule,
    ): void {
        $node = createFirewallRuleProbeAppHostNode();
        $rule = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'private-service',
            'source' => $source,
            'port' => '5173',
            'address_family' => 'both',
        ]);
        $snapshot = new FirewallRuleProbe()->snapshotFromUfwOutput(<<<UFW
            Status: active

                 To                         Action      From
                 --                         ------      ----
            {$observedRule}
            UFW);

        expect(new FirewallRuleProbe()->diff($rule, $snapshot))->toBe([]);
    })->with([
        'IPv4 network' => ['10.6.0.0/24', '[ 1] 5173/tcp                   ALLOW IN    10.6.0.0/24'],
        'IPv6 network' => ['2001:db8::/64', '[ 1] 5173/tcp (v6)              ALLOW IN    2001:db8::/64'],
    ]);

    it('matches wildcard both intent only when IPv4 and IPv6 UFW entries exist', function (): void {
        $node = createFirewallRuleProbeAppHostNode();
        $rule = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'public-service',
            'source' => 'any',
            'port' => '8443',
            'address_family' => 'both',
        ]);
        $completeSnapshot = new FirewallRuleProbe()->snapshotFromUfwOutput(<<<'UFW'
            Status: active

                 To                         Action      From
                 --                         ------      ----
            [ 1] 8443/tcp                   ALLOW IN    Anywhere
            [ 2] 8443/tcp (v6)              ALLOW IN    Anywhere (v6)
            UFW);
        $incompleteSnapshot = new FirewallRuleProbe()->snapshotFromUfwOutput(<<<'UFW'
            Status: active

                 To                         Action      From
                 --                         ------      ----
            [ 1] 8443/tcp                   ALLOW IN    Anywhere
            UFW);

        $probe = new FirewallRuleProbe;
        $incompleteDrift = $probe->diff($rule, $incompleteSnapshot);

        expect($probe->diff($rule, $completeSnapshot))
            ->toBe([])
            ->and(firewallProbeIssue($incompleteDrift, key: 'firewall_rule.rule_missing')?->kind)
            ->toBe(DriftKind::Missing)
            ->and(firewallProbeIssue($incompleteDrift, key: 'firewall_rule.rule_mismatch'))
            ->toBeNull();
    });

    it('reports a missing wildcard family regardless of which concrete family exists', function (
        string $observedRule,
        string $missingFamily,
    ): void {
        $node = createFirewallRuleProbeAppHostNode();
        $rule = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'public-service',
            'source' => 'any',
            'port' => '8443',
            'address_family' => 'both',
        ]);
        $snapshot = new FirewallRuleProbe()->snapshotFromUfwOutput(<<<UFW
            Status: active

                 To                         Action      From
                 --                         ------      ----
            {$observedRule}
            UFW);

        $drift = new FirewallRuleProbe()->diff($rule, $snapshot);

        expect(
            firewallProbeIssue($drift, key: 'firewall_rule.rule_missing')?->detail['expected']['address_family']
            ?? null,
        )
            ->toBe($missingFamily);
    })->with([
        'IPv6 is missing' => ['[ 1] 8443/tcp                   ALLOW IN    Anywhere', 'v6'],
        'IPv4 is missing' => ['[ 1] 8443/tcp (v6)              ALLOW IN    Anywhere (v6)', 'v4'],
    ]);

    it('derives both from a concrete destination when the source is unrestricted', function (): void {
        expect(FirewallRuleShapeCanonicalizer::effectiveAddressFamily(
            addressFamily: 'both',
            source: 'any',
            destination: '2001:db8::/64',
        ))->toBe('v6');
    });

    it('keeps a same-family wildcard shape difference as mismatch', function (): void {
        $node = createFirewallRuleProbeAppHostNode();
        $rule = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'public-service',
            'source' => 'any',
            'port' => '8443',
            'address_family' => 'both',
        ]);
        $snapshot = new FirewallRuleProbe()->snapshotFromUfwOutput(<<<'UFW'
            Status: active

                 To                         Action      From
                 --                         ------      ----
            [ 1] 8443/tcp                   ALLOW IN    10.6.0.0/24
            UFW);

        $drift = new FirewallRuleProbe()->diff($rule, $snapshot);

        expect(firewallProbeIssue($drift, key: 'firewall_rule.rule_mismatch')?->kind)
            ->toBe(DriftKind::Divergent);
    });

    it('treats host CIDR and bare host IP with derived family as equivalent', function (): void {
        $node = createFirewallRuleProbeAppHostNode();
        $rule = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'main1-valkey',
            'source' => '10.6.0.13/32',
            'port' => '6379',
            'protocol' => 'tcp',
            'address_family' => 'both',
        ]);
        $snapshot = new FirewallRuleProbe()->snapshotFromUfwOutput(<<<'UFW'
            Status: active

                 To                         Action      From
                 --                         ------      ----
            [ 1] 6379/tcp                   ALLOW IN    10.6.0.13
            UFW);

        $drift = new FirewallRuleProbe()->diff($rule, $snapshot);

        expect($drift)->toBe([]);
    });

    it('still reports a true source mismatch for non-equivalent host addresses', function (): void {
        $node = createFirewallRuleProbeAppHostNode();
        $rule = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'main1-valkey',
            'source' => '10.6.0.13/32',
            'port' => '6379',
            'protocol' => 'tcp',
            'address_family' => 'both',
        ]);
        $snapshot = new FirewallRuleProbe()->snapshotFromUfwOutput(<<<'UFW'
            Status: active

                 To                         Action      From
                 --                         ------      ----
            [ 1] 6379/tcp                   ALLOW IN    10.6.0.14
            UFW);

        $drift = new FirewallRuleProbe()->diff($rule, $snapshot);
        $issue = firewallProbeIssue($drift, 'firewall_rule.rule_mismatch');

        expect($issue?->kind)
            ->toBe(DriftKind::Divergent)
            ->and($issue?->detail['observed']['source'] ?? null)
            ->toBe('10.6.0.14');
    });

    it('matches node security baseline rules rendered with concrete UFW interfaces', function (): void {
        $node = createFirewallRuleProbeAppHostNode();
        $publicDeny = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'orbit-public-ssh-deny-v4',
            'action' => 'deny',
            'source' => '0.0.0.0/0',
            'port' => '22',
            'address_family' => 'v4',
            'interface' => 'public',
            'owner' => 'node-security',
        ]);
        $wireguardAllow = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'orbit-wireguard-ssh-allow-v4',
            'source' => '10.6.0.0/24',
            'port' => '22',
            'address_family' => 'v4',
            'interface' => 'wireguard',
            'owner' => 'node-security',
        ]);
        $publicDenyV6 = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'orbit-public-ssh-deny-v6',
            'action' => 'deny',
            'source' => '::/0',
            'port' => '22',
            'address_family' => 'v6',
            'interface' => 'public',
            'owner' => 'node-security',
        ]);
        $snapshot = new FirewallRuleProbe()->snapshotFromUfwOutput(<<<'UFW'
            Status: active

                 To                         Action      From
                 --                         ------      ----
            [ 1] 22/tcp on wg-orbit         ALLOW IN    10.6.0.0/24                # Orbit node security baseline permits SSH only through WireGuard.
            [ 2] 22/tcp on enp11s0          DENY IN     Anywhere                   # Orbit node security baseline denies public SSH after bootstrap.
            [ 3] 22/tcp (v6) on enp11s0     DENY IN     Anywhere (v6)              # Orbit node security baseline denies public SSH after bootstrap.
            UFW);

        $probe = new FirewallRuleProbe;

        expect($probe->diff($publicDeny, $snapshot))
            ->toBe([])
            ->and($probe->diff($wireguardAllow, $snapshot))
            ->toBe([])
            ->and($probe->diff($publicDenyV6, $snapshot))
            ->toBe([]);
    });

    it('matches inactive UFW staged node security rules from stored rule files', function (): void {
        $node = createFirewallRuleProbeAppHostNode();
        $publicDeny = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'orbit-public-ssh-deny-v4',
            'action' => 'deny',
            'source' => '0.0.0.0/0',
            'port' => '22',
            'address_family' => 'v4',
            'interface' => 'public',
            'owner' => 'node-security',
        ]);
        $wireguardAllow = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'orbit-wireguard-ssh-allow-v4',
            'source' => '10.6.0.0/24',
            'port' => '22',
            'address_family' => 'v4',
            'interface' => 'wireguard',
            'owner' => 'node-security',
        ]);
        $publicDenyV6 = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'orbit-public-ssh-deny-v6',
            'action' => 'deny',
            'source' => '::/0',
            'port' => '22',
            'address_family' => 'v6',
            'interface' => 'public',
            'owner' => 'node-security',
        ]);
        $snapshot = new FirewallRuleProbe()->snapshotFromUfwOutput(<<<'UFW'
            Status: inactive
            __orbit_ufw_file:user:-A ufw-user-input -i wg-orbit -p tcp --dport 22 -s 10.6.0.0/24 -j ACCEPT
            __orbit_ufw_file:user:-A ufw-user-input -i eth0 -p tcp --dport 22 -j DROP
            __orbit_ufw_file:user6:-A ufw6-user-input -i eth0 -p tcp --dport 22 -j DROP
            UFW);

        $probe = new FirewallRuleProbe;

        expect($probe->diff($publicDeny, $snapshot))
            ->toBe([])
            ->and($probe->diff($wireguardAllow, $snapshot))
            ->toBe([])
            ->and($probe->diff($publicDenyV6, $snapshot))
            ->toBe([]);
    });
});

describe('firewall registry probe foundation', function (): void {
    it('passes complete firewall rules on active Ubuntu app nodes', function (): void {
        $node = createFirewallRuleProbeAppHostNode();
        $rule = FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'local-vite']);

        $drift = new FirewallRuleProbe()->diff($rule, new ProbeSnapshot([]));

        expect($drift)->toBe([]);
    });

    it('passes complete firewall rules on active gateway role assignments', function (): void {
        $node = createFirewallRuleProbeGatewayAssignmentNode();
        $rule = FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'public-https', 'port' => '443']);

        $drift = new FirewallRuleProbe()->diff($rule, new ProbeSnapshot([]));

        expect($drift)->toBeEmpty();
    });

    it('detects incomplete firewall rule records', function (): void {
        $node = createFirewallRuleProbeAppHostNode();
        $id = DB::table('firewall_rules')->insertGetId([
            'node_id' => $node->id,
            'name' => 'broken',
            'direction' => 'sideways',
            'action' => 'allow',
            'source' => 'any',
            'destination' => null,
            'port' => '443',
            'protocol' => 'tcp',
            'source_hash' => str_repeat('0', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rule = FirewallRule::findOrFail($id);
        $drift = new FirewallRuleProbe()->diff($rule, new ProbeSnapshot([]));

        expect(firewallProbeIssue($drift, 'firewall_rule.record_incomplete')?->kind)->toBe(DriftKind::Missing);
    });

    it('requires active Ubuntu gateway or app target nodes', function (callable $createNode): void {
        $node = $createNode();
        $rule = FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'local-vite']);

        $drift = new FirewallRuleProbe()->diff($rule, new ProbeSnapshot([]));

        expect(firewallProbeIssue($drift, 'firewall_rule.node_invalid')?->kind)->toBe(DriftKind::Divergent);
    })->with([
        'unassigned node' => [fn (): Node => Node::factory()->create(['status' => 'active', 'platform' => 'ubuntu'])],
        'inactive app node' => [
            fn (): Node => Node::factory()->appDev()->create(['status' => 'inactive', 'platform' => 'ubuntu']),
        ],
        'unsupported platform' => [
            fn (): Node => Node::factory()->appDev()->create(['status' => 'active', 'platform' => 'macos']),
        ],
    ]);

    it('treats every firewall-eligible role as a valid target node', function (string $role): void {
        $node = Node::factory()->create(['name' => "{$role}-fw-node", 'status' => 'active', 'platform' => 'ubuntu']);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => $role,
            'status' => 'active',
            'settings' => in_array($role, ['app-dev', 'agent'], true) ? ['tld' => $role] : [],
        ]);
        $rule = FirewallRule::factory()->create(['node_id' => $node->id, 'name' => "{$role}-rule"]);

        $drift = new FirewallRuleProbe()->diff($rule, new ProbeSnapshot([]));

        expect(firewallProbeIssue($drift, 'firewall_rule.node_invalid'))->toBeNull();
    })->with(['gateway', 'router', 'app-dev', 'app-prod', 'database', 'agent', 'ingress']);

    it('treats versioned Ubuntu platforms as valid firewall target nodes', function (): void {
        $node = Node::factory()->create(['name' => 'app-fw-node', 'status' => 'active', 'platform' => 'ubuntu_24-04']);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
        $rule = FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'app-rule']);

        $drift = new FirewallRuleProbe()->diff($rule, new ProbeSnapshot([]));

        expect(firewallProbeIssue($drift, 'firewall_rule.node_invalid'))->toBeNull();
    });

    it('detects baseline policy boundary conflicts', function (): void {
        $node = createFirewallRuleProbeAppHostNode();
        $rule = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'public-ssh',
            'direction' => 'incoming',
            'action' => 'allow',
            'source' => 'any',
            'destination' => null,
            'port' => '22',
            'protocol' => 'tcp',
        ]);

        $drift = new FirewallRuleProbe()->diff($rule, new ProbeSnapshot([]));

        expect(firewallProbeIssue($drift, 'firewall_rule.baseline_conflict')?->kind)->toBe(DriftKind::Divergent);
    });
});

describe('firewall adopt handlers', function (): void {
    it('adopts observed backend rules not in the registry', function (): void {
        $node = createFirewallRuleProbeAppHostNode();
        $snapshot = new FirewallRuleProbe()->snapshotFromUfwOutput(<<<'UFW'
            Status: active

                 To                         Action      From
                 --                         ------      ----
            [ 1] 5173/tcp                   ALLOW IN    10.6.0.0/24
            UFW);
        $results = new FirewallRuleProbe()->adopt($node, $snapshot);

        expect($results)
            ->toHaveCount(1)
            ->and($results[0]->action)
            ->toBe(AdoptAction::Created)
            ->and($results[0]->summary)
            ->toContain('Adopted firewall rule')
            ->and(FirewallRule::query()->where('node_id', $node->id)->count())
            ->toBe(1);

        $adopted = FirewallRule::query()->where('node_id', $node->id)->first();

        expect($adopted->name)
            ->toBe('incoming-allow-5173-tcp')
            ->and($adopted->direction)
            ->toBe('incoming')
            ->and($adopted->action)
            ->toBe('allow')
            ->and($adopted->source)
            ->toBe('10.6.0.0/24')
            ->and($adopted->port)
            ->toBe('5173')
            ->and($adopted->protocol)
            ->toBe('tcp');
    });

    it('skips baseline bootstrap rules during adoption', function (): void {
        $node = createFirewallRuleProbeAppHostNode();
        $snapshot = new FirewallRuleProbe()->snapshotFromUfwOutput(<<<'UFW'
            Status: active

                 To                         Action      From
                 --                         ------      ----
            [ 1] 22/tcp                     ALLOW IN    Anywhere
            UFW);
        $results = new FirewallRuleProbe()->adopt($node, $snapshot);

        expect($results)->toBeEmpty()->and(FirewallRule::query()->where('node_id', $node->id)->count())->toBe(0);
    });

    it('skips rules already present in the registry', function (): void {
        $node = createFirewallRuleProbeAppHostNode();
        FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'local-vite',
            'source' => '10.6.0.0/24',
            'port' => '5173',
        ]);
        $snapshot = new FirewallRuleProbe()->snapshotFromUfwOutput(<<<'UFW'
            Status: active

                 To                         Action      From
                 --                         ------      ----
            [ 1] 5173/tcp                   ALLOW IN    10.6.0.0/24
            UFW);
        $results = new FirewallRuleProbe()->adopt($node, $snapshot);

        expect($results)->toBeEmpty()->and(FirewallRule::query()->where('node_id', $node->id)->count())->toBe(1);
    });

    it('reports conflict when name collides with different identity', function (): void {
        $node = createFirewallRuleProbeAppHostNode();
        FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'incoming-allow-5173-tcp',
            'source' => '192.168.1.0/24',
            'port' => '5173',
        ]);
        $snapshot = new FirewallRuleProbe()->snapshotFromUfwOutput(<<<'UFW'
            Status: active

                 To                         Action      From
                 --                         ------      ----
            [ 1] 5173/tcp                   ALLOW IN    10.6.0.0/24
            UFW);
        $results = new FirewallRuleProbe()->adopt($node, $snapshot);

        expect($results)
            ->toHaveCount(1)
            ->and($results[0]->action)
            ->toBe(AdoptAction::Conflict)
            ->and($results[0]->summary)
            ->toContain('Name collision');
    });

    it('derives name from orbit: prefix comment', function (): void {
        $node = createFirewallRuleProbeAppHostNode();
        $snapshot = new FirewallRuleProbe()->snapshotFromUfwOutput(<<<'UFW'
            Status: active

                 To                         Action      From
                 --                         ------      ----
            [ 1] 5173/tcp                   ALLOW IN    10.6.0.0/24             # orbit:local-vite
            UFW);
        $results = new FirewallRuleProbe()->adopt($node, $snapshot);

        expect($results[0]->action)->toBe(AdoptAction::Created);

        $adopted = FirewallRule::query()->where('node_id', $node->id)->first();

        expect($adopted->name)->toBe('local-vite');
    });
});
