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

it('captures a budget for the proof reset only', function (): void {
    expect(OperationRunBudgets::scriptSeconds('bin/proof-fleet', ['reset', '3246', '--from-ready']))->toBe(360)
        ->and(OperationRunBudgets::scriptSeconds('bin/proof-fleet', ['status']))->toBeNull()
        ->and(OperationRunBudgets::scriptSeconds('scripts/dependencies', ['routine']))->toBeNull();
});

it('gives a script its captured budget and every other script the budget of the history', function (): void {
    $reset = scriptOperation(['script' => 'bin/proof-fleet', 'args' => ['reset', '3246', '--from-ready'], 'run_budget_seconds' => 360]);
    $other = scriptOperation(['script' => 'scripts/dependencies', 'args' => ['routine']]);

    expect(OperationRunBudgets::seconds($reset))->toBe(360)->and(OperationRunBudgets::seconds($other))->toBe(1800);

    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'swamp', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit']));
    Operation::query()->create(['kind' => 'tasks.script', 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => $node->id, 'input' => [],
        'state' => ['swamp_run_id' => 'run-0'], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1,
        'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(10)->addSeconds(70)]);

    expect(OperationRunBudgets::seconds($other))->toBe(120)->and(OperationRunBudgets::seconds($reset))->toBe(360);
});

it('ignores a captured budget on any other kind', function (): void {
    $operation = scriptOperation(['run_budget_seconds' => 5], 'tasks.check');

    expect(OperationRunBudgets::seconds($operation))->not->toBe(5);
});
