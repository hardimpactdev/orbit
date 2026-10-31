<?php

declare(strict_types=1);

use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Operation;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;

require_once __DIR__.'/../../Support/Fleet/NodeSecretOutput.php';

beforeEach(function (): void {
    config(['swamp.run_token' => 'shared.scoped.fixture']);
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
});

it('completes an agent-only footprint without inventing optional child Operations', function (): void {
    [, , $path, $run] = nodeSecretOutput($this);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('agent.configure', ['secret_hash' => hash('sha256', 'disposable-hash'), 'restarted' => true, 'unclosed' => []]);
    app()->instance(AgentCommandRunner::class, $agents);
    $result = $this->postJson($path.'/run', $run)->assertOk()->json('data');
    $this->postJson($path.'/step', ['worker' => 'hash-worker', 'key' => 'configured', 'name' => 'footprint-agent',
        'status' => 'succeeded', 'result' => $result])->assertOk();
    $final = $this->postJson($path.'/run', ['worker' => 'hash-worker', 'key' => 'footprint-result',
        'transport' => 'gateway', 'name' => 'node.footprint-result', 'input' => (object) []])->assertOk()->json('data');
    $this->postJson($path.'/step', ['worker' => 'hash-worker', 'key' => 'proven', 'name' => 'footprint-result',
        'status' => 'succeeded', 'result' => $final])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'hash-worker', 'status' => 'succeeded', 'result' => $final])->assertOk();
});

it('completes a footprint with a proven skipped Caddy child through the real completion boundary', function (): void {
    [, $operation, $path, $run] = nodeSecretOutput($this);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('agent.configure', ['secret_hash' => hash('sha256', 'disposable-hash'), 'restarted' => true, 'unclosed' => []]);
    app()->instance(AgentCommandRunner::class, $agents);
    $this->postJson($path.'/run', $run)->assertOk();
    $input = $operation->fresh()->input;
    $intent = ['key' => 'footprint-caddy', 'kind' => 'route.caddy_converge', 'node_id' => $operation->node_id,
        'input' => ['reason' => 'node-footprint-proof']];
    $input['_footprint']['artifacts'][] = ['name' => 'caddy', 'digest' => 'caddy-digest', 'required' => true, 'repair' => false];
    $input['_footprint']['children']['caddy'] = array_diff_key($intent, ['key' => true]);
    $operation->update(['input' => $input, 'state' => [...$operation->fresh()->state, 'child_intents' => [$intent]]]);
    $childId = $this->postJson($path.'/children', ['worker' => 'hash-worker', 'key' => 'footprint-caddy'])->assertAccepted()->json('data.id');
    $child = Operation::query()->findOrFail($childId);
    // A stamped terminal child is the completion boundary's authority; Node effects are covered by its owning family.
    $child->update(['status' => 'failed', 'result' => ['stage' => 'validate', 'error_code' => 'caddy.publication_failed'],
        'error_code' => 'caddy.publication_failed', 'finished_at' => now(), 'lock_key' => null]);
    $final = $this->postJson($path.'/run', ['worker' => 'hash-worker', 'key' => 'footprint-result',
        'transport' => 'gateway', 'name' => 'node.footprint-result', 'input' => (object) []])->assertOk()->json('data');
    expect($final['skipped'][0]['key'])->toBe('footprint-caddy');
    $this->postJson($path.'/step', ['worker' => 'hash-worker', 'key' => 'proven', 'name' => 'footprint-result',
        'status' => 'succeeded', 'result' => $final])->assertOk();
    $contract = app(OperationExecutionContract::class);
    $canonical = $contract->completionResult($operation->refresh(), $final, true);
    $contract->assertStep($operation, 'footprint-result', $canonical);
    foreach (['reason', 'digest'] as $field) {
        $forged = $canonical;
        if ($field === 'reason') {
            $forged['skipped'][0]['reason'] = 'invented reason';
        } else {
            $forged['digest'] = 'invented digest';
        }
        expect(fn () => $contract->assertStep($operation, 'footprint-result', $forged))
            ->toThrow(ResourceOperationException::class, 'matching keyed transport receipt');
        $this->postJson($path.'/step', ['worker' => 'hash-worker', 'key' => 'forged-'.$field, 'name' => 'footprint-result',
            'status' => 'succeeded', 'result' => $forged])->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
        $this->postJson($path.'/complete', ['worker' => 'hash-worker', 'status' => 'succeeded', 'result' => $forged])
            ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    }
    $foreign = $canonical;
    $foreign['skipped'][0]['child_id'] = $operation->id;
    $this->postJson($path.'/complete', ['worker' => 'hash-worker', 'status' => 'succeeded', 'result' => $foreign])
        ->assertConflict()->assertJsonPath('error.code', 'operation.child_unproven');
    $commands = $agents->commands();
    $completion = $this->postJson($path.'/complete', ['worker' => 'hash-worker', 'status' => 'succeeded', 'result' => $final]);
    $this->assertNull($completion->json('error'), json_encode($completion->json('error'), JSON_THROW_ON_ERROR));
    $completion->assertOk()
        ->assertJsonPath('data.result.skipped.0.key', 'footprint-caddy')
        ->assertJsonPath('data.result.skipped.0.child_id', $child->id);
    $this->postJson($path.'/complete', ['worker' => 'hash-worker', 'status' => 'succeeded', 'result' => $final])->assertOk();
    expect($operation->refresh()->lock_key)->toBeNull()
        ->and($operation->state['executor_runs']['footprint-result']['result'])->toBe($final)
        ->and($operation->state['steps']['footprint-result'])->toBe($final)
        ->and($agents->commands())->toBe($commands);
});
