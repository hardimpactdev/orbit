<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationProjection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

final readonly class PublicCommandInputsProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['public'], 'execution_secrets' => [
            'pi_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-12-pi-token'],
        ], 'operation_id' => 999, 'node_id' => 999, 'kind' => 'foreign.kind'];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return $operation;
    }
}

it('starts a converted workflow with public intent and vault names while preserving its admitted envelope', function (): void {
    $contribution = new class implements DirectOperationContribution
    {
        public function definitions(): array
        {
            return ['test.command_inputs' => ['workflow' => 'operation-test-command-inputs',
                'projection' => PublicCommandInputsProjection::class, 'workflow_inputs' => true]];
        }
    };
    app()->instance(DirectOperation::class, new DirectOperation([$contribution]));
    $node = Node::query()->create(['name' => 'command-input-host', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active', 'public_ssh_host' => '192.0.2.27']);
    $operation = Operation::query()->create(['kind' => 'test.command_inputs', 'target_type' => 'task_vm',
        'target_id' => 12, 'node_id' => $node->id, 'status' => 'queued', 'sequence' => 1,
        'input' => ['public' => ['task_vm_id' => 12, 'name' => 'tvm-12', 'image_fingerprint' => str_repeat('a', 64)],
            'private' => 'must-stay-out-of-history'], 'state' => []]);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.start-token']);

    app(StartSwampRun::class)->start($operation);

    expect($socket->sent[0]['payload']['inputs'])->toBe([
        'intent' => ['task_vm_id' => 12, 'name' => 'tvm-12', 'image_fingerprint' => str_repeat('a', 64)],
        'execution_secrets' => ['pi_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-12-pi-token']],
        'operation_id' => $operation->id, 'node_id' => $node->id, 'kind' => 'test.command_inputs',
    ]);
    expect(json_encode($socket->sent, JSON_THROW_ON_ERROR))->not->toContain('must-stay-out-of-history');
    expect($operation->fresh()->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676');
});

it('keeps unconverted declarations on their original envelope without copying stored input', function (): void {
    $node = Node::query()->create(['name' => 'command-input-host', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active', 'public_ssh_host' => '192.0.2.27']);
    $operation = Operation::query()->create(['kind' => 'route.private_dns_publish', 'target_type' => 'node_route_projection',
        'target_id' => $node->id, 'node_id' => $node->id, 'status' => 'queued', 'sequence' => 1,
        'input' => ['private' => 'must-stay-out-of-history'], 'state' => []]);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.start-token']);

    app(StartSwampRun::class)->start($operation);

    expect($socket->sent[0]['payload']['inputs'])->toBe([
        'operation_id' => $operation->id, 'kind' => 'route.private_dns_publish', 'node_id' => $node->id,
    ]);
});
