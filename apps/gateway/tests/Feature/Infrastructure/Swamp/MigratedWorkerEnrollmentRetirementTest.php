<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Infrastructure\Nodes\NodeWorkerEnrollment;
use App\Models\Operation;

it('gives migrated kinds no per-Node enrollment secret owner or declaration', function (string $kind): void {
    $operation = new Operation(['kind' => $kind, 'target_type' => 'node', 'target_id' => 9, 'node_id' => 9,
        'input' => ['worker_credentials' => ['$secret' => 'node.worker_credentials', 'id' => 9]]]);
    expect(app(NodeWorkerEnrollment::class)->ownerId($operation))->toBeNull();
    $definition = app(DirectOperation::class)->definition($kind);
    expect($definition)->not->toBeNull();
    expect($definition['private_material'] ?? [])->not->toHaveKey('worker_credentials');
    expect($definition['secret_outputs'] ?? [])->not->toHaveKey('worker_credentials');
})->with(['schedule.create', 'schedule.enable', 'schedule.run', 'schedule.destroy', 'database.read',
    'vault.sync', 'conn.pairing_revoke', 'route.update', 'tasks.publish']);
