<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Models\Node;
use App\Models\Operation;

it('settles only unbound unattempted cancellation without touching native children', function (string $status, bool $bound): void {
    $node = $this->markAsGateway(Node::query()->create(['name' => 'cancel-gateway', 'status' => 'active', 'platform' => 'linux',
        'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $state = $bound ? ['route_publication_writer' => 'direct'] : [];
    if ($bound) {
        $state['swamp_run_id'] = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    }
    $operation = Operation::query()->create(['kind' => 'route.caddy_converge', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'status' => $status, 'sequence' => 1, 'input' => [], 'state' => $state]);
    $child = Operation::query()->create(['kind' => 'route.caddy_converge', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'parent_operation_id' => $operation->id, 'status' => 'queued', 'sequence' => 1, 'input' => [], 'state' => $state]);

    $this->call('POST', '/api/v1/operations/'.$operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk()->assertJsonPath('data.status', $bound ? $status : 'cancelled');
    $cancelled = $operation->fresh();
    expect($cancelled->cancel_requested_at)->not->toBeNull();
    expect($cancelled->finished_at !== null)->toBe(! $bound);
    expect($cancelled->result)->toBeNull();
    expect($cancelled->state)->toBe($state);
    expect($child->fresh()->cancel_requested_at)->toBeNull();
    expect($child->fresh()->status)->toBe(OperationStatus::Queued);
    $sequence = $cancelled->sequence;

    app(CancelOperationAction::class)->execute($cancelled);
    expect($cancelled->fresh()->sequence)->toBe($sequence);
})->with(['queued', 'running'])->with(['bound' => true, 'unbound' => false]);
