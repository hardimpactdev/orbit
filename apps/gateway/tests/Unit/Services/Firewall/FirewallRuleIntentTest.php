<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Firewall\FirewallRuleIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process as ProcessFacade;
use Orbit\Sdk\Laravel\GatewayApiException;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(RemoteShell::class, new FirewallRuleIntentRecordingRemoteShell);
    ProcessFacade::preventStrayProcesses();
    ProcessFacade::fake([
        '*' => ProcessFacade::result(),
    ]);
});

/**
 * @param  list<string>  $permissions
 */
function grantFirewallRuleIntentAccess(Node $caller, Node $servingNode, array $permissions = ['*']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $servingNode->id,
        'permissions' => json_encode($permissions),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function createFirewallRuleIntentAppHostNode(array $attributes = []): Node
{
    $node = Node::factory()->create([
        'name' => 'app-1',
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

function createFirewallRuleIntentRoleNode(string $role, array $attributes = []): Node
{
    $node = Node::factory()->create([
        'name' => "{$role}-1",
        'platform' => 'ubuntu',
        'status' => 'active',
        ...$attributes,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => [],
    ]);

    return $node;
}

describe('FirewallRuleIntent', function (): void {
    it('creates idempotent firewall intent and enacts it immediately', function (): void {
        $node = createFirewallRuleIntentAppHostNode();

        $result = app(FirewallRuleIntent::class)->store(
            action: 'allow',
            name: 'local-vite',
            nodeName: 'app-1',
            direction: 'incoming',
            source: '10.6.0.0/24',
            destination: null,
            port: '5173',
            protocol: 'tcp',
            reason: 'local development',
        );

        expect(FirewallRule::query()->count())
            ->toBe(1)
            ->and($result['data']['rule']['name'])
            ->toBe('local-vite')
            ->and($result['data']['rule']['node'])
            ->toBe($node->name)
            ->and($result['meta']['action'])
            ->toBe('created')
            ->and($result['meta']['backend_enacted'])
            ->toBeTrue()
            ->and($result['meta']['warnings'])
            ->toBe([]);

        $again = app(FirewallRuleIntent::class)->store(
            'allow',
            'local-vite',
            'app-1',
            'incoming',
            '10.6.0.0/24',
            null,
            '5173',
            'tcp',
            'local development',
        );

        expect(FirewallRule::query()->count())->toBe(1)->and($again['meta']['action'])->toBe('converged');
    });

    it('preserves a stored reason when a converging run omits it', function (): void {
        createFirewallRuleIntentAppHostNode();

        app(FirewallRuleIntent::class)->store(
            action: 'allow',
            name: 'local-vite',
            nodeName: 'app-1',
            direction: 'incoming',
            source: '10.6.0.0/24',
            destination: null,
            port: '5173',
            protocol: 'tcp',
            reason: 'keep this operator note',
        );

        app(FirewallRuleIntent::class)->store(
            action: 'allow',
            name: 'local-vite',
            nodeName: 'app-1',
            direction: 'incoming',
            source: '10.6.0.0/24',
            destination: null,
            port: '5173',
            protocol: 'tcp',
            reason: null,
        );

        expect(FirewallRule::query()->where('name', 'local-vite')->sole()->reason)
            ->toBe('keep this operator note');
    });

    it('replaces a stored reason when the run supplies a new one', function (): void {
        createFirewallRuleIntentAppHostNode();

        app(FirewallRuleIntent::class)->store(
            action: 'allow',
            name: 'local-vite',
            nodeName: 'app-1',
            direction: 'incoming',
            source: '10.6.0.0/24',
            destination: null,
            port: '5173',
            protocol: 'tcp',
            reason: 'original note',
        );

        app(FirewallRuleIntent::class)->store(
            action: 'allow',
            name: 'local-vite',
            nodeName: 'app-1',
            direction: 'incoming',
            source: '10.6.0.0/24',
            destination: null,
            port: '5173',
            protocol: 'tcp',
            reason: 'updated note',
        );

        expect(FirewallRule::query()->where('name', 'local-vite')->sole()->reason)
            ->toBe('updated note');
    });

    it('rejects converging onto a protected role-owned rule', function (): void {
        $node = createFirewallRuleIntentAppHostNode();

        FirewallRule::query()->create([
            'node_id' => $node->id,
            'name' => 'metrics-scrape',
            'direction' => 'incoming',
            'action' => 'allow',
            'source' => '10.6.0.0/24',
            'destination' => null,
            'port' => '9100',
            'protocol' => 'tcp',
            'reason' => 'role owned',
            'owner' => 'metrics',
            'source_hash' => 'seed',
        ]);

        expect(fn () => app(FirewallRuleIntent::class)->store(
            action: 'allow',
            name: 'metrics-scrape',
            nodeName: 'app-1',
            direction: 'incoming',
            source: '10.6.0.0/24',
            destination: null,
            port: '9100',
            protocol: 'tcp',
            reason: 'user attempt',
        ))
            ->toThrow(GatewayApiException::class);

        $rule = FirewallRule::query()->where('name', 'metrics-scrape')->sole();

        expect($rule->owner)
            ->toBe('metrics')
            ->and($rule->protected)
            ->toBeTrue()
            ->and($rule->reason)
            ->toBe('role owned');
    });

    it('rejects same-name different policy before mutation', function (): void {
        $node = createFirewallRuleIntentAppHostNode();
        FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'local-vite', 'port' => '5173']);

        app(FirewallRuleIntent::class)->store(
            'allow',
            'local-vite',
            'app-1',
            'incoming',
            'any',
            null,
            '8080',
            'tcp',
            null,
        );
    })->throws(GatewayApiException::class, 'A different firewall rule already uses this name on the selected node.');

    it('authorizes non-gateway callers through node access grants', function (): void {
        $caller = Node::factory()->appDev()->create(['platform' => 'ubuntu']);
        $node = createFirewallRuleIntentAppHostNode();
        grantFirewallRuleIntentAccess($caller, $node);

        app(FirewallRuleIntent::class)->store(
            'deny',
            'block-valkey',
            'app-1',
            'incoming',
            'any',
            null,
            '6379',
            'tcp',
            null,
            $caller,
        );

        expect(FirewallRule::query()->where('name', 'block-valkey')->exists())->toBeTrue();
    });

    it('denies non-gateway callers that only have firewall read permission', function (): void {
        $caller = Node::factory()->appDev()->create(['platform' => 'ubuntu']);
        $node = createFirewallRuleIntentAppHostNode();
        grantFirewallRuleIntentAccess($caller, $node, ['firewall_rule:read']);

        app(FirewallRuleIntent::class)->store(
            'deny',
            'block-valkey',
            'app-1',
            'incoming',
            'any',
            null,
            '6379',
            'tcp',
            null,
            $caller,
        );
    })->throws(
        GatewayApiException::class,
        'This node is not authorized to manage firewall rules for the selected node.',
    );

    it('removes intent idempotently and cleans up the backend immediately', function (): void {
        $node = createFirewallRuleIntentAppHostNode();
        FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'local-vite']);

        $removed = app(FirewallRuleIntent::class)->remove('local-vite', 'app-1');
        $again = app(FirewallRuleIntent::class)->remove('local-vite', 'app-1');

        expect(FirewallRule::query()->count())
            ->toBe(0)
            ->and($removed['meta']['backend_removed'])
            ->toBeTrue()
            ->and($removed['meta']['warnings'])
            ->toBe([])
            ->and($again['data']['rule']['status'])
            ->toBe('already_absent');
    });

    it('defers backend enactment failures only in the Docker E2E feature lane', function (): void {
        $previousProvider = getenv('ORBIT_E2E_TOPOLOGY_PROVIDER');
        putenv('ORBIT_E2E_TOPOLOGY_PROVIDER=docker');
        app()->instance(RemoteShell::class, new FirewallRuleIntentFailingRemoteShell);

        try {
            createFirewallRuleIntentAppHostNode();

            $result = app(FirewallRuleIntent::class)->store(
                action: 'allow',
                name: 'local-vite',
                nodeName: 'app-1',
                direction: 'incoming',
                source: '10.6.0.0/24',
                destination: null,
                port: '5173',
                protocol: 'tcp',
                reason: 'local development',
            );

            expect(FirewallRule::query()->where('name', 'local-vite')->exists())
                ->toBeTrue()
                ->and($result['meta']['backend_enacted'])
                ->toBeFalse()
                ->and($result['meta']['warnings'][0]['code'])
                ->toBe('firewall_rule.enactment_deferred');
        } finally {
            $previousProvider === false
                ? putenv('ORBIT_E2E_TOPOLOGY_PROVIDER')
                : putenv("ORBIT_E2E_TOPOLOGY_PROVIDER={$previousProvider}");
        }
    });

    it('rejects protected firewall rules from user-facing removal', function (): void {
        $node = createFirewallRuleIntentAppHostNode();
        FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'orbit-public-ssh-deny-v4',
            'owner' => 'node-security',
        ]);

        app(FirewallRuleIntent::class)->remove('orbit-public-ssh-deny-v4', 'app-1');
    })->throws(GatewayApiException::class, 'Protected firewall rules cannot be removed through firewall commands.');

    it('blocks bootstrap policy mutations', function (): void {
        createFirewallRuleIntentAppHostNode();

        app(FirewallRuleIntent::class)->store(
            'allow',
            'ssh-public',
            'app-1',
            'incoming',
            'any',
            null,
            '22',
            'tcp',
            null,
        );
    })->throws(GatewayApiException::class, 'The requested rule would mutate node bootstrap policy.');

    it('allows firewall rules for every active Ubuntu role target', function (string $role): void {
        $node = createFirewallRuleIntentRoleNode($role, ['name' => "{$role}-node"]);

        app(FirewallRuleIntent::class)->store(
            'deny',
            'block-test',
            $node->name,
            'incoming',
            'any',
            null,
            '8080',
            'tcp',
            null,
        );

        expect(FirewallRule::query()->where('node_id', $node->id)->where('name', 'block-test')->exists())->toBeTrue();
    })->with([
        'gateway',
        'router',
        'app-dev',
        'app-prod',
        'database',
        'agent',
        'ingress',
    ]);

    it('creates and removes firewall intent on versioned Ubuntu nodes', function (): void {
        $node = createFirewallRuleIntentAppHostNode(['platform' => 'ubuntu_24-04']);

        $stored = app(FirewallRuleIntent::class)->store(
            action: 'allow',
            name: 'local-vite',
            nodeName: 'app-1',
            direction: 'incoming',
            source: '10.6.0.0/24',
            destination: null,
            port: '5173',
            protocol: 'tcp',
            reason: 'local development',
        );

        expect(FirewallRule::query()->count())
            ->toBe(1)
            ->and($stored['data']['rule']['node'])
            ->toBe($node->name)
            ->and($stored['meta']['action'])
            ->toBe('created')
            ->and($stored['meta']['backend_enacted'])
            ->toBeTrue();

        $removed = app(FirewallRuleIntent::class)->remove('local-vite', 'app-1');

        expect(FirewallRule::query()->count())
            ->toBe(0)
            ->and($removed['meta']['backend_removed'])
            ->toBeTrue();
    });

    it('rejects firewall writes on inactive roleless or non-Ubuntu nodes', function (
        array $attributes,
        bool $assignRole,
    ): void {
        $node = Node::factory()->create([
            'name' => 'target-1',
            'platform' => 'ubuntu_24-04',
            'status' => 'active',
            ...$attributes,
        ]);

        if ($assignRole) {
            NodeRoleAssignment::factory()->create([
                'node_id' => $node->id,
                'role' => 'app-dev',
                'status' => 'active',
                'settings' => ['tld' => 'test'],
            ]);
        }

        app(FirewallRuleIntent::class)->store(
            'deny',
            'block-test',
            $node->name,
            'incoming',
            'any',
            null,
            '8080',
            'tcp',
            null,
        );
    })->with([
        'inactive versioned ubuntu' => [['status' => 'inactive'], true],
        'roleless versioned ubuntu' => [[], false],
        'non-ubuntu' => [['platform' => 'macos'], true],
        'hyphenated ubuntu' => [['platform' => 'ubuntu-24-04'], true],
    ])->throws(GatewayApiException::class, 'The selected node is not a firewall target.');
});

final class FirewallRuleIntentRecordingRemoteShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class FirewallRuleIntentFailingRemoteShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if (($options['throw'] ?? false) === true) {
            throw new RuntimeException('sudo: ufw: command not found');
        }

        return new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'sudo: ufw: command not found', durationMs: 1);
    }
}
