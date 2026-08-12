<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Doctor\DoctorFamilyProbeRunner;
use App\Services\Doctor\DoctorFirewallRuleFamilyProbe;
use App\Services\Doctor\DoctorIssueFactory;
use App\Services\Firewall\FirewallRuleProbe;
use App\Services\Firewall\RemoteFirewallRuleProbe;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('preserves firewall-rule issue order, payloads, and progress', function (): void {
    /** @var Node $node */
    $node = Node::factory()->create([
        'name' => 'firewall-family-node',
        'platform' => 'ubuntu',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);
    /** @var FirewallRule $firstRule */
    $firstRule = FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'zulu']);
    /** @var FirewallRule $secondRule */
    $secondRule = FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'alpha']);
    $executor = new DoctorFirewallRuleFamilyExecutor([
        doctor_firewall_rule_family_probe_result(),
        doctor_firewall_rule_family_probe_result(),
    ]);
    $events = [];

    $issues = doctor_firewall_rule_family_probe($executor)->probe(
        node: $node,
        key: null,
        onFamilyProgress: static function (
            string $family,
            string $phase,
            array $issues,
            ?int $completed,
            ?int $total,
        ) use (&$events): void {
            $events[] = compact('family', 'phase', 'issues', 'completed', 'total');
        },
    );

    expect(collect($issues)->pluck('detail.rule')->all())
        ->toBe([$firstRule->name, $secondRule->name])
        ->and(collect($issues)->pluck('node')->all())
        ->toBe([$node->name, $node->name])
        ->and(collect($issues)->pluck('key')->all())
        ->toBe(['firewall_rule.rule_missing', 'firewall_rule.rule_missing'])
        ->and(collect($events)->pluck('family')->unique()->values()->all())
        ->toBe(['firewall_rule'])
        ->and(collect($events)->pluck('phase')->all())
        ->toBe(['running', 'running', 'done'])
        ->and(collect($events)->pluck('completed')->all())
        ->toBe([0, 1, null])
        ->and(collect($events)->pluck('total')->all())
        ->toBe([2, 2, null])
        ->and($events[2]['issues'][0]['detail']['rule'] ?? null)
        ->toBe($firstRule->name)
        ->and($executor->calls)
        ->toBe(2);
});

it('converts one firewall transport failure without duplicating it', function (): void {
    /** @var Node $node */
    $node = Node::factory()->create([
        'name' => 'firewall-transport-node',
        'platform' => 'ubuntu',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);
    FirewallRule::factory()->create(['node_id' => $node->id]);
    $executor = new DoctorFirewallRuleFamilyExecutor([
        new RemoteLocalExecutorTransportFailed('agent push unavailable'),
    ]);
    $events = [];

    $issues = doctor_firewall_rule_family_probe($executor)->probe(
        node: $node,
        key: null,
        onFamilyProgress: static function (
            string $family,
            string $phase,
            array $issues,
            ?int $completed,
            ?int $total,
        ) use (&$events): void {
            $events[] = compact('family', 'phase', 'issues', 'completed', 'total');
        },
    );

    expect($issues)
        ->toHaveCount(1)
        ->and($issues[0]->key)
        ->toBe('firewall_rule.remote_shell_probe_failed')
        ->and($issues[0]->node)
        ->toBe($node->name)
        ->and($events)
        ->toHaveCount(2)
        ->and($events[1]['issues'])
        ->toHaveCount(1)
        ->and($events[1]['issues'][0]['key'] ?? null)
        ->toBe('firewall_rule.remote_shell_probe_failed')
        ->and($executor->calls)
        ->toBe(1);
});

function doctor_firewall_rule_family_probe(
    RunsInternalCommands $executor,
): DoctorFirewallRuleFamilyProbe {
    return new DoctorFirewallRuleFamilyProbe(
        app(DoctorFamilyProbeRunner::class),
        new FirewallRuleProbe(new RemoteFirewallRuleProbe($executor)),
        app(DoctorIssueFactory::class),
    );
}

function doctor_firewall_rule_family_probe_result(): RemoteShellResult
{
    return new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode([
            'success' => [
                'data' => [
                    'output' => "Status: active\n\nTo Action From\n-- ------ ----\n",
                ],
                'meta' => [],
            ],
        ], JSON_THROW_ON_ERROR),
        stderr: '',
        durationMs: 1,
    );
}

/** @mago-expect lint:file-name */
final class DoctorFirewallRuleFamilyExecutor implements RunsInternalCommands
{
    public int $calls = 0;

    /**
     * @param  list<RemoteShellResult|Throwable>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $this->calls++;
        $result = array_shift($this->results);

        if ($result instanceof Throwable) {
            throw $result;
        }

        if (! $result instanceof RemoteShellResult) {
            throw new LogicException('No firewall probe result remains.');
        }

        return $result;
    }
}
