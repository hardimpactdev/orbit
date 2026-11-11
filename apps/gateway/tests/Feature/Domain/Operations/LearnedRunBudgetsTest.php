<?php

declare(strict_types=1);

use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** A finished Operation of `$kind` that ran `$seconds` and ended `$status`, with a native run, so history may count it. */
function learnedBudgetRun(string $kind, int $seconds, OperationStatus $status = OperationStatus::Succeeded, int $target = 1): Operation
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->firstOrCreate(['name' => 'swamp'], ['status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.15', 'wireguard_ip' => '10.44.0.15', 'user' => 'orbit']));
    $finished = now()->subHour();

    return Operation::query()->create(['kind' => $kind, 'target_type' => 'instance', 'target_id' => $target, 'node_id' => $node->id,
        'lock_key' => "instance:{$target}", 'input' => [], 'status' => $status, 'state' => ['swamp_run_id' => "run-{$kind}-{$target}"],
        'sequence' => 2, 'attempts' => 1, 'started_at' => $finished->copy()->subSeconds($seconds), 'finished_at' => $finished]);
}

it('learns a two-minute soft limit from a quick success without a fifteen-minute floor', function (): void {
    learnedBudgetRun('database.sqlite_seed', 56);

    expect(OperationRunBudgets::historySeconds('database.sqlite_seed'))->toBe(120);
});

it('keeps a learned budget at 1.5 times the longest success, rounded up to a minute', function (): void {
    learnedBudgetRun('database.sqlite_seed', 907);

    expect(OperationRunBudgets::historySeconds('database.sqlite_seed'))->toBe(1380);
});

it('keeps 30 minutes for a kind with no success, and ignores failed runs', function (): void {
    learnedBudgetRun('instance.clone', 40, OperationStatus::Failed);

    expect(OperationRunBudgets::historySeconds('instance.clone'))->toBe(1800);
});
