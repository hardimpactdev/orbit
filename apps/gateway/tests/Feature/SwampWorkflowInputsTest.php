<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Shared\ResourceOperationException;
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

final readonly class DescriptorCommandInputsProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => ['target' => 12], 'execution_secrets' => $operation->input['references']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return $operation;
    }
}

function commandInputDefinition(array $options = []): DirectOperation
{
    $direct = new DirectOperation([new class($options) implements DirectOperationContribution
    {
        public function __construct(private array $options) {}

        public function definitions(): array
        {
            return ['test.command_descriptor' => $this->options + ['workflow' => 'operation-test-command-descriptor',
                'projection' => DescriptorCommandInputsProjection::class, 'workflow_inputs' => true]];
        }
    }]);
    app()->instance(DirectOperation::class, $direct);

    return $direct;
}

it('accepts fixed terminal selectors independently of the full-input opt-in', function (): void {
    $direct = commandInputDefinition(['workflow_inputs' => false, 'terminal_result' => ['job' => 'horizon', 'step' => 'result']]);
    $operation = new Operation(['kind' => 'test.command_descriptor', 'node_id' => 14, 'input' => ['references' => []]]);
    $operation->id = 27;

    expect($direct->workflowInputs($operation))->toBe(['operation_id' => 27, 'kind' => 'test.command_descriptor', 'node_id' => 14]);
    expect($direct->definition('test.command_descriptor')['terminal_result'])->toBe(['job' => 'horizon', 'step' => 'result']);
});

it('rejects malformed fixed terminal declarations', function (mixed $selector): void {
    expect(fn () => commandInputDefinition(['terminal_result' => $selector]))->toThrow(LogicException::class);
})->with([
    [null], [true], [[]], [['job' => 'horizon']], [['job' => '', 'step' => 'result']],
    [['job' => 'horizon', 'step' => false]], [['job' => 'horizon', 'step' => 'result', 'path' => 'arbitrary']],
]);

it('serializes an empty execution descriptor map as an object', function (): void {
    $operation = new Operation(['kind' => 'test.command_descriptor', 'node_id' => 14, 'input' => ['references' => []]]);
    $operation->id = 27;
    $input = commandInputDefinition()->workflowInputs($operation);
    expect(json_encode($input, JSON_THROW_ON_ERROR))->toContain('"execution_secrets":{}');
});

it('refuses non-reference secret maps before a native request can contain values', function (mixed $references): void {
    $operation = new Operation(['kind' => 'test.command_descriptor', 'node_id' => 14, 'input' => ['references' => $references]]);
    $operation->id = 27;
    expect(fn () => commandInputDefinition()->workflowInputs($operation))->toThrow(ResourceOperationException::class);
})->with([
    ['raw-private-value'],
    [[['vault' => 'orbit-control-inputs', 'key' => 'bootstrap-identity-node-14']]],
    [['identity' => ['vault' => 'orbit-control-inputs', 'key' => 'key', 'value' => 'raw-private-value']]],
    [['identity' => ['vault' => 'orbit-control-inputs']]],
    [['identity' => ['vault' => [], 'key' => 'key']]],
    [['identity' => ['vault' => 'orbit-control-inputs', 'key' => "private\nvalue"]]],
    [['bad field' => ['vault' => 'orbit-control-inputs', 'key' => 'key']]],
]);

final readonly class SubmissionOnlyCommandInputsProjection implements DirectOperationProjection, DirectOperationWorkflowInput
{
    public function input(Operation $operation): array
    {
        return ['public_callback' => true];
    }

    public function workflowInput(Operation $operation): array
    {
        return ['intent' => ['submitted' => true]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return $operation;
    }
}

it('separates submission-only inputs from effect-free public callback inputs', function (): void {
    $direct = new DirectOperation([new class implements DirectOperationContribution
    {
        public function definitions(): array
        {
            return ['test.submission_only' => ['workflow' => 'operation-test-submission-only',
                'projection' => SubmissionOnlyCommandInputsProjection::class, 'workflow_inputs' => true]];
        }
    }]);
    app()->instance(DirectOperation::class, $direct);
    $operation = new Operation(['kind' => 'test.submission_only', 'node_id' => 9]);
    $operation->id = 27;

    expect($direct->projection($operation)->input($operation))->toBe(['public_callback' => true])
        ->and($direct->workflowInputs($operation))->toEqual(['intent' => ['submitted' => true],
            'execution_secrets' => (object) [], 'operation_id' => 27, 'kind' => 'test.submission_only', 'node_id' => 9]);
});
