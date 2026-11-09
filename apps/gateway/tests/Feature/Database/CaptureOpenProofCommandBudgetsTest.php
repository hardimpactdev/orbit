<?php

declare(strict_types=1);

use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('gives a proof run that is open at the release the 3,600 seconds it was admitted under, and leaves the others', function (): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'shark', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.11', 'public_ssh_host' => '10.44.0.11', 'user' => 'orbit']));
    $run = static fn (OperationStatus $status, array $input = []): Operation => Operation::query()->create(['kind' => 'proof.command', 'target_type' => 'task_group',
        'target_id' => 1, 'node_id' => $node->id, 'status' => $status, 'input' => ['command' => 'firewall:allow', ...$input], 'state' => ['swamp_run_id' => 'run'], 'attempts' => 1]);
    $open = $run(OperationStatus::Running);
    $captured = $run(OperationStatus::Running, ['run_budget_seconds' => 4500]);
    $ended = $run(OperationStatus::Failed);
    $migration = require database_path('migrations/2027_02_02_000000_capture_open_proof_command_budgets.php');

    $migration->up();
    $migration->up();

    expect($open->refresh()->input)->toBe(['command' => 'firewall:allow', 'run_budget_seconds' => 3600])
        ->and(OperationRunBudgets::seconds($open))->toBe(3600)
        ->and($captured->refresh()->input['run_budget_seconds'])->toBe(4500)
        ->and($ended->refresh()->input)->toBe(['command' => 'firewall:allow']);
});
