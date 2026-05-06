<?php

declare(strict_types=1);

use App\Data\Doctor\ProbeSnapshot;
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

    it('returns an empty foundation snapshot before live backend probing is added', function (): void {
        $rule = new FirewallRule(['name' => 'local-vite']);

        expect((new FirewallRuleProbe)->introspect($rule)->isEmpty())->toBeTrue();
    });
});

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
