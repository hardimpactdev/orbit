<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\Doctor\DoctorFirewallRuleRestorer;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('returns null when the issue does not identify a saved rule on the node', function (array $detail): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-node']);

    expect(app(DoctorFirewallRuleRestorer::class)->apply(
        $node,
        'firewall_rule.rule_missing',
        $detail,
    ))->toBeNull();
})->with([
    'missing rule detail' => [[]],
    'unknown rule' => [['rule' => 'unknown-rule']],
]);

it('keeps duplicate rule names scoped to the selected node', function (): void {
    $decoyNode = Node::factory()->appDev()->create(['name' => 'decoy-node']);
    $targetNode = Node::factory()->appDev()->create(['name' => 'target-node']);
    FirewallRule::factory()->for($decoyNode, 'node')->create(['name' => 'local-https']);
    FirewallRule::factory()->for($targetNode, 'node')->create(['name' => 'local-https']);
    $executor = doctor_firewall_rule_restorer_executor([
        new RemoteShellResult(0, "Status: active\n", '', 1),
        new RemoteShellResult(0, '', '', 1),
    ]);
    app()->instance(RunsInternalCommands::class, $executor);

    $action = app(DoctorFirewallRuleRestorer::class)->apply(
        $targetNode,
        'firewall_rule.rule_missing',
        ['rule' => 'local-https'],
    );

    expect($action)
        ->toMatchArray([
            'family' => 'firewall_rule',
            'node' => 'target-node',
            'code' => 'firewall_rule.rule_missing',
            'key' => 'firewall_rule.rule_missing',
            'mode' => 'fix',
            'status' => 'completed',
            'details' => [
                'rule' => 'local-https',
                'convergence_status' => 'changed',
            ],
        ])
        ->and($executor->nodeNames)
        ->toBe(['target-node', 'target-node']);
});

it('preserves the failed restore action when firewall convergence fails', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-node']);
    FirewallRule::factory()->for($node, 'node')->create(['name' => 'local-https']);
    app()->instance(RunsInternalCommands::class, doctor_firewall_rule_restorer_executor([
        new RemoteShellResult(0, "Status: active\n", '', 1),
        new RemoteShellResult(1, '', 'apply failed', 1),
    ]));

    expect(app(DoctorFirewallRuleRestorer::class)->apply(
        $node,
        'firewall_rule.rule_missing',
        ['rule' => 'local-https'],
    ))->toBe([
        'family' => 'firewall_rule',
        'node' => 'app-node',
        'code' => 'firewall_rule.rule_missing',
        'key' => 'firewall_rule.rule_missing',
        'mode' => 'restore',
        'status' => 'failed',
        'summary' => 'Failed to fix firewall_rule.rule_missing.',
        'details' => ['error' => 'apply failed'],
    ]);
});

it('leaves unsupported issue codes to the fixer support contract', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-node']);
    FirewallRule::factory()->for($node, 'node')->create(['name' => 'local-https']);
    $executor = doctor_firewall_rule_restorer_executor([]);
    app()->instance(RunsInternalCommands::class, $executor);

    expect(app(DoctorFirewallRuleRestorer::class)->apply(
        $node,
        'firewall_rule.rule_extra',
        ['rule' => 'local-https'],
    ))
        ->toBeNull()
        ->and($executor->nodeNames)
        ->toBeEmpty();
});

/**
 * @param  list<RemoteShellResult>  $results
 */
function doctor_firewall_rule_restorer_executor(array $results): object
{
    return new class($results) implements RunsInternalCommands {
        /** @var list<string> */
        public array $nodeNames = [];

        /** @param list<RemoteShellResult> $results */
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
            $this->nodeNames[] = $node->name;

            return array_shift($this->results) ?? new RemoteShellResult(1, '', 'Unexpected command.', 1);
        }
    };
}
