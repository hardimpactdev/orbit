<?php

declare(strict_types=1);

use App\Infrastructure\DatabaseConnections\DatabaseConnectionInput;
use App\Models\DatabaseConnection;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Database\Eloquent\Collection;

it('admits the recorded production owner without requesting any Node command', function (): void {
    $node = new Node(['id' => 7, 'user' => 'orbit', 'status' => 'active', 'wireguard_ip' => '10.44.0.7']);
    $node->id = 7;
    $node->setRelation('roles', new Collection([new NodeRole(['role' => 'app-prod'])]));
    $connection = new DatabaseConnection(['slug' => 'local', 'driver' => 'sqlite', 'node_id' => 7, 'path' => '/home/app/shared/database.sqlite']);
    $connection->setRelation('node', $node);
    $connection->setRelation('ownerInstance', new Instance(['node_id' => 7, 'production_user' => 'orbit-app-7']));

    expect((new DatabaseConnectionInput)->sqlite($connection, 'SELECT 1'))->toBe(['owner' => 'orbit-app-7', 'path' => '/home/app/shared/database.sqlite', 'sql' => 'SELECT 1']);
});
