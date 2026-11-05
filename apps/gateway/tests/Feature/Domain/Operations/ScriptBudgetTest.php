<?php

declare(strict_types=1);

use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function scriptOperation(array $input, string $kind = 'tasks.script'): Operation
{
    return (new Operation)->forceFill(['kind' => $kind, 'input' => $input, 'state' => ['swamp_run_id' => 'run-1'], 'status' => OperationStatus::Running, 'attempts' => 1]);
}

it('gives a script its captured budget', function (): void {
    expect(OperationRunBudgets::seconds(scriptOperation(['script' => 'scripts/dependencies', 'run_budget_seconds' => 360])))->toBe(360);
});

it('captures the budget of the history for every script and check', function (): void {
    expect(OperationRunBudgets::scriptBudget())->toBe(1800);

    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'swamp', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit']));
    Operation::query()->create(['kind' => 'tasks.script', 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => $node->id, 'input' => [],
        'state' => ['swamp_run_id' => 'run-0'], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1,
        'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(10)->addSeconds(70)]);

    expect(OperationRunBudgets::scriptBudget())->toBe(120)
        ->and(OperationRunBudgets::seconds(scriptOperation(['run_budget_seconds' => 90], 'tasks.check')))->toBe(90);
});

it('ignores a captured budget on a kind that does not capture one', function (): void {
    expect(OperationRunBudgets::seconds(scriptOperation(['run_budget_seconds' => 5], 'tasks.publish')))->not->toBe(5);
});
