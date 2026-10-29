<?php

declare(strict_types=1);

use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Operation;
use Tests\Support\FakeAgentCommandRunner;

require_once __DIR__.'/../../Support/Fleet/NodeSecretOutput.php';

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
    expect($final['skipped'][0]['key'])->toBe('[REDACTED]');
    $this->postJson($path.'/step', ['worker' => 'hash-worker', 'key' => 'proven', 'name' => 'footprint-result',
        'status' => 'succeeded', 'result' => $final])->assertOk();
    $completion = $this->postJson($path.'/complete', ['worker' => 'hash-worker', 'status' => 'succeeded', 'result' => $final]);
    $this->assertNull($completion->json('error'), json_encode($completion->json('error'), JSON_THROW_ON_ERROR));
    $completion->assertOk()
        ->assertJsonPath('data.result.skipped.0.key', 'footprint-caddy')
        ->assertJsonPath('data.result.skipped.0.child_id', $child->id);
});
