<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeConvergeCommandInput;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Node;
use App\Models\Operation;

it('submits the complete admitted public footprint without secret references or internal staging handles', function (): void {
    $intent = ['schema_version' => 1, 'node_id' => 12, 'agent_sha256' => str_repeat('a', 64),
        'force_artifacts' => true, 'first_visit' => false,
        'artifacts' => [['name' => 'tmpfiles', 'digest' => str_repeat('b', 64), 'required' => true, 'repair' => false]],
        'payloads' => ['tmpfiles' => ['rule' => 'e /tmp/orbit-test - - - 1d']],
        'network' => null, 'refusal' => null,
        'secret_hash' => ['$secret' => 'node.agent_secret_hash', 'id' => 12]];
    $operation = new Operation(['kind' => 'node.converge', 'node_id' => 12,
        'input' => ['_converge' => $intent, '_worker_enrollment' => 'internal-stage-reference',
            'worker_credentials' => ['$secret' => 'node.worker_credentials', 'id' => 12]]]);
    $operation->setAttribute('id', 123);

    $input = app(NodeConvergeCommandInput::class)->forOperation($operation);
    unset($intent['secret_hash']);

    expect($input)->toBe(['intent' => $intent, 'execution_secrets' => []]);
    expect(json_encode($input))->not->toContain('internal-stage-reference', '$secret', 'worker_credentials');
});

it('uses the shared execution copy for an owning required agent hash and sends only the descriptor', function (): void {
    $hash = hash('sha256', 'synthetic-existing-agent-secret');
    $node = Node::query()->create(['name' => 'command-footprint', 'user' => 'orbit', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.27']);
    $node->forceFill(['agent_secret_hash' => $hash])->save();
    $operation = new Operation(['kind' => 'node.converge', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'input' => ['_converge' => ['node_id' => $node->id, 'artifacts' => [['name' => 'agent', 'required' => true]],
            'secret_hash' => ['$secret' => 'node.agent_secret_hash', 'id' => $node->id]]]]);
    $operation->id = 2000000 + $node->id;
    config(['swamp.run_token' => 'synthetic-gateway-token', 'swamp.ca_path' => '/public/root.pem']);
    $runner = new class implements ProcessRunner
    {
        public array $arguments = [];

        public ?string $value = null;

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->arguments = $invocation->arguments;
            $this->value = stream_get_contents($invocation->protectedInput->stream());

            return new CommandResult(0, '{}', '', 1, false);
        }
    };
    app()->instance(ProcessRunner::class, $runner);

    $input = app(NodeConvergeCommandInput::class)->forOperation($operation);

    expect($input['execution_secrets'])->toBe(['secret_hash' => ['vault' => 'orbit-node-inputs', 'key' => 'operation-'.$operation->id.'-agent-secret-hash']])
        ->and($runner->value)->toBe(json_encode($hash, JSON_THROW_ON_ERROR))
        ->and(json_encode([$input, $runner->arguments], JSON_THROW_ON_ERROR))->not->toContain($hash, 'synthetic-gateway-token');
});
