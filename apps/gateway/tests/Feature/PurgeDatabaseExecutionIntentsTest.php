<?php

declare(strict_types=1);

use App\Actions\DatabaseServers\PurgeDatabaseExecutionIntentsAction;
use App\Models\DatabaseExecutionIntent;
use App\Models\Node;
use App\Models\Operation;

it('keeps protected material for active or recently finished Operations and expires finished material', function (): void {
    $this->freezeTime();
    $node = Node::query()->create(['name' => 'database-dev', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9']);
    $intents = [];
    foreach (['active', 'recent', 'expired', 'unused'] as $name) {
        $intents[$name] = DatabaseExecutionIntent::query()->create(['target_type' => 'database_server', 'target_id' => 1, 'node_id' => $node->id,
            'payload' => ['commands' => [], 'projection' => []], 'created_at' => now()->subHours(25)]);
    }
    foreach (['active' => null, 'recent' => now()->subHour(), 'expired' => now()->subHours(25)] as $name => $finished) {
        Operation::query()->create(['kind' => 'database.create', 'target_type' => 'database_server', 'target_id' => 1, 'node_id' => $node->id,
            'status' => $finished === null ? 'running' : 'failed', 'finished_at' => $finished, 'input' => ['intent_id' => $intents[$name]->id]]);
    }
    expect(app(PurgeDatabaseExecutionIntentsAction::class)->execute())->toBe(2);
    expect(DatabaseExecutionIntent::query()->pluck('id')->all())->toBe([$intents['active']->id, $intents['recent']->id]);
    $this->assertDatabaseCount('operations', 3);
});

it('retains material referenced by an active lifecycle parent until its retry window expires', function (): void {
    $this->freezeTime();
    $node = Node::query()->create(['name' => 'owner-parent', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9']);
    $intent = DatabaseExecutionIntent::query()->create(['target_type' => 'instance', 'target_id' => 1, 'node_id' => $node->id,
        'payload' => ['commands' => [], 'projection' => []], 'created_at' => now()->subHours(25)]);
    $operation = Operation::query()->create(['kind' => 'instance.destroy', 'target_type' => 'instance', 'target_id' => 1, 'node_id' => $node->id,
        'status' => 'running', 'input' => ['direct_destroy' => ['database' => ['intent_id' => $intent->id]]]]);

    expect(app(PurgeDatabaseExecutionIntentsAction::class)->execute())->toBe(0);
    $this->assertModelExists($intent);

    $operation->update(['status' => 'failed', 'finished_at' => now()->subHours(25)]);
    expect(app(PurgeDatabaseExecutionIntentsAction::class)->execute())->toBe(1);
    $this->assertModelMissing($intent);
});
