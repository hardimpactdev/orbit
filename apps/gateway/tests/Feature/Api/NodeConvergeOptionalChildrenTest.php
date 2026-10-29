<?php

declare(strict_types=1);

use App\Infrastructure\AgentCommands\AgentCommandRunner;
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
