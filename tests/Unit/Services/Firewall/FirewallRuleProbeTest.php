<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\Firewall\FirewallRuleProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function firewallProbeIssue(array $drift, string $key): mixed
{
    return collect($drift)->first(fn ($entry): bool => $entry->key === $key);
}

describe('FirewallRuleProbe interface', function (): void {
    it('has key and label', function (): void {
        $probe = new FirewallRuleProbe;

        expect($probe->key())->toBe('firewall_rule')
            ->and($probe->label())->toBe('Firewall rules');
    });

    it('returns an empty snapshot when no target node is available', function (): void {
        $rule = new FirewallRule(['name' => 'local-vite']);

        expect((new FirewallRuleProbe)->introspect($rule)->isEmpty())->toBeTrue();
    });
});

describe('firewall backend UFW reality', function (): void {
    it('introspects UFW rules from the target node', function (): void {
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active', 'platform' => 'ubuntu']);
        $rule = FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'local-vite']);
        $shell = new FirewallProbeRecordingRemoteShell(<<<'UFW'
Status: active

     To                         Action      From
     --                         ------      ----
[ 1] 5173/tcp                   ALLOW IN    10.6.0.0/24
[ 2] 5173/tcp (v6)              ALLOW IN    Anywhere (v6)
UFW);

        $snapshot = (new FirewallRuleProbe($shell))->introspect($rule);

        expect($snapshot->get('incoming:allow:10.6.0.0/24:any:5173:tcp'))->toMatchArray([
            'direction' => 'incoming',
            'action' => 'allow',
            'source' => '10.6.0.0/24',
            'port' => '5173',
            'protocol' => 'tcp',
        ])
            ->and($shell->nodes[0]->is($node))->toBeTrue()
            ->and($shell->scripts[0])->toBe('sudo ufw status numbered');
    });

    it('detects missing backend rules after UFW inspection', function (): void {
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active', 'platform' => 'ubuntu']);
        $rule = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'local-vite',
            'source' => '10.6.0.0/24',
            'port' => '5173',
        ]);
        $shell = new FirewallProbeRecordingRemoteShell(<<<'UFW'
Status: active

     To                         Action      From
     --                         ------      ----
UFW);

        $snapshot = (new FirewallRuleProbe($shell))->introspect($rule);
        $drift = (new FirewallRuleProbe)->diff($rule, $snapshot);

        expect(firewallProbeIssue($drift, 'firewall_rule.rule_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects backend rule shape mismatches', function (): void {
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active', 'platform' => 'ubuntu']);
        $rule = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'local-vite',
            'source' => '10.6.0.0/24',
            'port' => '5173',
        ]);
        $shell = new FirewallProbeRecordingRemoteShell(<<<'UFW'
Status: active

     To                         Action      From
     --                         ------      ----
[ 1] 5173/tcp                   ALLOW IN    Anywhere
UFW);

        $snapshot = (new FirewallRuleProbe($shell))->introspect($rule);
        $drift = (new FirewallRuleProbe)->diff($rule, $snapshot);
        $issue = firewallProbeIssue($drift, 'firewall_rule.rule_mismatch');

        expect($issue?->kind)->toBe(DriftKind::Divergent)
            ->and($issue?->detail['expected']['source'] ?? null)->toBe('10.6.0.0/24')
            ->and($issue?->detail['observed']['source'] ?? null)->toBe('any');
    });

    it('passes when backend rule shape matches gateway intent', function (): void {
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active', 'platform' => 'ubuntu']);
        $rule = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'local-vite',
            'source' => '10.6.0.0/24',
            'port' => '5173',
        ]);
        $shell = new FirewallProbeRecordingRemoteShell(<<<'UFW'
Status: active

     To                         Action      From
     --                         ------      ----
[ 1] 5173/tcp                   ALLOW IN    10.6.0.0/24
UFW);

        $snapshot = (new FirewallRuleProbe($shell))->introspect($rule);
        $drift = (new FirewallRuleProbe)->diff($rule, $snapshot);

        expect($drift)->toBe([]);
    });
});

final class FirewallProbeRecordingRemoteShell implements RemoteShell
{
    /** @var list<Node> */
    public array $nodes = [];

    /** @var list<string> */
    public array $scripts = [];

    public function __construct(
        private readonly string $stdout,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: $this->stdout, stderr: '', durationMs: 1);
    }
}

describe('firewall registry probe foundation', function (): void {
    it('passes complete firewall rules on active Ubuntu app nodes', function (): void {
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active', 'platform' => 'ubuntu']);
        $rule = FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'local-vite']);

        $drift = (new FirewallRuleProbe)->diff($rule, new ProbeSnapshot([]));

        expect($drift)->toBe([]);
    });

    it('detects incomplete firewall rule records', function (): void {
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active', 'platform' => 'ubuntu']);
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
        $drift = (new FirewallRuleProbe)->diff($rule, new ProbeSnapshot([]));

        expect(firewallProbeIssue($drift, 'firewall_rule.record_incomplete')?->kind)->toBe(DriftKind::Missing);
    });

    it('requires active Ubuntu gateway or app target nodes', function (array $nodeState): void {
        $node = Node::factory()->create($nodeState);
        $rule = FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'local-vite']);

        $drift = (new FirewallRuleProbe)->diff($rule, new ProbeSnapshot([]));

        expect(firewallProbeIssue($drift, 'firewall_rule.node_invalid')?->kind)->toBe(DriftKind::Divergent);
    })->with([
        'control node' => [['role' => 'control', 'status' => 'active', 'platform' => 'ubuntu']],
        'inactive app node' => [['role' => 'app', 'status' => 'inactive', 'platform' => 'ubuntu']],
        'unsupported platform' => [['role' => 'app', 'status' => 'active', 'platform' => 'macos']],
    ]);

    it('detects baseline policy boundary conflicts', function (): void {
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active', 'platform' => 'ubuntu']);
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

        $drift = (new FirewallRuleProbe)->diff($rule, new ProbeSnapshot([]));

        expect(firewallProbeIssue($drift, 'firewall_rule.baseline_conflict')?->kind)->toBe(DriftKind::Divergent);
    });
});
