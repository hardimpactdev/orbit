<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseSqliteProjection;
use App\Models\Operation;

it('publishes only recorded safe SQLite placement descriptors', function (string $kind): void {
    $source = ['node_id' => 12, 'agent_sha256' => str_repeat('a', 64), 'variant' => 'seed'];
    $target = ['node_id' => 17, 'agent_sha256' => str_repeat('b', 64)];
    $operation = new Operation(['kind' => $kind, 'input' => [
        'source' => $source, 'target' => $target, 'unrelated' => 'private-sentinel',
    ]]);

    expect((new DatabaseSqliteProjection)->publicInput($operation, 12))->toBe(['source' => $source, 'target' => $target]);
})->with(['database.sqlite_export', 'database.sqlite_seed', 'database.sqlite_seed_abandon']);
