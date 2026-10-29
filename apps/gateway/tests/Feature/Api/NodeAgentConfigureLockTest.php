<?php

declare(strict_types=1);

use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Nodes\NodeLocks;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

require_once __DIR__.'/../../Support/Fleet/NodeSecretOutput.php';

it('holds the same agent lock through remote configuration and encrypted owner projection', function (): void {
    [$node, , $path, $run] = nodeSecretOutput($this);
    $lockName = 'node-agent:id:'.$node->id;
    $check = function () use ($lockName): void {
        $other = app(NodeLocks::class)->lock($lockName, 420);
        expect($other->get())->toBeFalse();
    };
    $agents = new FakeAgentCommandRunner;
    $agents->answer('agent.configure', function () use ($check): array {
        $check();

        return ['secret_hash' => str_repeat('b', 64), 'restarted' => true, 'unclosed' => []];
    });
    app()->instance(AgentCommandRunner::class, $agents);
    $projected = false;
    Node::saving(function (Node $saving) use ($node, $check, &$projected): void {
        if ($saving->id === $node->id && $saving->isDirty('agent_secret_hash')) {
            $check();
            $projected = true;
        }
    });
    $this->postJson($path.'/run', $run)->assertOk();
    expect($projected)->toBeTrue()->and($node->fresh()->agent_secret_hash)->toBe(str_repeat('b', 64));
    $lock = app(NodeLocks::class)->lock($lockName, 420);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('refuses a synchronous agent owner before hydration or starting a Swamp effect', function (): void {
    [$node, $operation, $path, $run] = nodeSecretOutput($this);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('agent.configure', ['secret_hash' => str_repeat('b', 64), 'restarted' => true, 'unclosed' => []]);
    app()->instance(AgentCommandRunner::class, $agents);
    $lock = app(NodeLocks::class)->lock('node-agent:id:'.$node->id, 420);
    expect($lock->get())->toBeTrue();
    try {
        $this->postJson($path.'/run', $run)->assertConflict()->assertJsonPath('error.code', 'agent.converge_busy');
        expect($agents->commands())->toBe([])
            ->and($operation->fresh()->state['executor_runs']['footprint-agent']['started'] ?? false)->toBeFalse();
    } finally {
        $lock->release();
    }
    $this->postJson($path.'/run', $run)->assertOk();
});

it('releases the Node agent lock after an uncertain reply', function (): void {
    [$node, , $path, $run] = nodeSecretOutput($this);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('agent.configure', static fn () => throw new RuntimeException('Lost reply'));
    app()->instance(AgentCommandRunner::class, $agents);
    $this->postJson($path.'/run', $run)->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
    $lock = app(NodeLocks::class)->lock('node-agent:id:'.$node->id, 420);
    expect($lock->get())->toBeTrue();
    $lock->release();
});
