<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeRemovalPlan;
use App\Domain\Nodes\NodeRemovalProjection;
use App\Domain\Nodes\NodeRemovalRecords;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

it('projects only the terminal restoration decision and leaves uncertain membership intact', function (bool $restored): void {
    FleetFixtures::bind();
    $node = FleetFixtures::node('terminal-removal');
    $snapshot = app(NodeRemovalPlan::class)->build($node, true);
    app(NodeRemovalRecords::class)->begin($snapshot);
    $run = '3a332a4f-0911-4e9d-9a18-09899b7b1864';
    $input = ['_node_removal' => $snapshot, '_removal' => ['snapshot' => $snapshot,
        'participants' => [['node_id' => 13, 'dns' => ['records' => 'captured']]],
        'restore_participants' => [['node_id' => 13, 'dns' => ['records' => 'prior']]], 'routes' => ['routes' => []]]];
    $operation = Operation::query()->create(['kind' => 'node.remove', 'node_id' => $node->id, 'target_type' => 'node', 'target_id' => $node->id,
        'status' => 'running', 'sequence' => 1, 'state' => ['swamp_run_id' => $run], 'input' => $input]);
    $projection = app(NodeRemovalProjection::class);
    expect($projection->input($operation))->toBe($input['_removal']);

    $projection->apply($operation, ['run_id' => $run, 'status' => 'failed', 'started_at' => '2026-10-31T08:00:00Z', 'finished_at' => '2026-10-31T08:00:01Z',
        'error_code' => 'node.removal_failed', 'result' => ['schema_version' => 1, 'node_id' => $node->id, 'removed' => false,
            'restored' => $restored, 'error_code' => 'node.removal_failed', 'raw_private' => 'discarded']]);

    expect($node->fresh()->status)->toBe($restored ? LifecycleStatus::Active : LifecycleStatus::Removing);
    expect($operation->fresh()->result)->not->toHaveKey('raw_private');
    expect($operation->fresh()->status->value)->toBe('failed');
})->with([true, false]);
