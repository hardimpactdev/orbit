<?php

declare(strict_types=1);

use App\Actions\Nodes\RekeyNodeAgentAction;
use App\Domain\Operations\OperationAttemptLease;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Middleware\RequireNodeAgentSecret;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Nodes\NodeLocks;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FleetFixtures;

require_once __DIR__.'/../../Support/Fleet/NodeSecretOutput.php';

function uncertainNodeAgent($test): array
{
    [$node, $operation, $path, $run] = nodeSecretOutput($test);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('agent.configure', function (): array {
        throw new RuntimeException('lost configure reply');
    });
    app()->instance(AgentCommandRunner::class, $agents);
    $test->postJson($path.'/run', $run)->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');

    return [$node, $operation->fresh(), $run['input']];
}

it('rekeys only the admitted Node and restores authentication without proving the lost effect', function (): void {
    [$node, $operation, $input] = uncertainNodeAgent($this);
    $secret = 'new-recovery-agent-secret';
    $hash = hash('sha256', $secret);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('agent.configure', function (array $actual, Node $owner) use ($node, $input, $hash): array {
        expect($owner->id)->toBe($node->id)->and($actual)->toBe([...$input, 'secret_hash' => null]);

        return ['secret_hash' => $hash, 'restarted' => true, 'unclosed' => []];
    });
    app()->instance(AgentCommandRunner::class, $agents);
    $before = $operation->state['executor_runs']['footprint-agent'];
    $this->artisan('orbit:node-agent-rekey', ['node' => (string) $node->id, 'operation' => (string) $operation->id])
        ->expectsOutputToContain('Agent authentication restored')->doesntExpectOutputToContain($secret)->doesntExpectOutputToContain($hash)->assertSuccessful();
    $this->artisan('orbit:node-agent-rekey', ['node' => (string) $node->id, 'operation' => (string) $operation->id])
        ->assertSuccessful();
    expect($agents->commands())->toBe(['agent.configure'])
        ->and($node->fresh()->agent_secret_hash)->toBe($hash)
        ->and(DB::table('nodes')->where('id', $node->id)->value('agent_secret_hash'))->not->toContain($hash)
        ->and($operation->fresh()->state['executor_runs']['footprint-agent'])->toBe($before)
        ->and(json_encode($operation->fresh()->state))->not->toContain($secret, $hash);
    $request = Request::create('/api/v1/agent/status', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
    $request->setUserResolver(fn () => $node->fresh());
    expect(app(RequireNodeAgentSecret::class)->handle($request, fn () => response('accepted'))->getStatusCode())->toBe(200);
});

it('refuses a foreign owner or a configure run that is not open and uncertain', function (string $case): void {
    [$node, $operation] = uncertainNodeAgent($this);
    $id = $node->id;
    if ($case === 'owner') {
        $id = FleetFixtures::node('foreign-rekey-owner')->id;
    } elseif ($case === 'terminal') {
        $operation->update(['status' => 'failed', 'lock_key' => null]);
    } elseif ($case === 'hold') {
        $operation->update(['lock_key' => null]);
    } elseif ($case === 'target') {
        $operation->update(['target_id' => $node->id + 1]);
    } elseif ($case === 'kind') {
        $operation->update(['kind' => 'node.settings']);
    } else {
        $state = $operation->state;
        match ($case) {
            'not-started' => $state['executor_runs']['footprint-agent']['started'] = false,
            'proven' => $state['executor_runs']['footprint-agent']['result'] = ['restarted' => true],
            'command' => $state['executor_runs']['footprint-agent']['name'] = 'agent.status',
            'transport' => $state['executor_runs']['footprint-agent']['transport'] = 'remote_program',
        };
        $operation->update(['state' => $state]);
    }
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    $this->artisan('orbit:node-agent-rekey', ['node' => (string) $id, 'operation' => (string) $operation->id])->assertFailed();
    expect($agents->commands())->toBe([])->and($node->fresh()->agent_secret_hash)->toBe(str_repeat('a', 64));
})->with(['owner', 'terminal', 'hold', 'target', 'kind', 'not-started', 'proven', 'command', 'transport']);

it('does not race an effect still holding the shared attempt lease', function (): void {
    [$node, $operation] = uncertainNodeAgent($this);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    app(OperationAttemptLease::class)->hold($operation->id, function () use ($node, $operation): void {
        expect(fn () => app(RekeyNodeAgentAction::class)->execute($node->id, $operation->id))->toThrow(ResourceOperationException::class);
    });
    expect($agents->commands())->toBe([]);
});

it('refuses a retained synchronous agent converge holding the Node lock', function (): void {
    [$node, $operation] = uncertainNodeAgent($this);
    $lock = app(NodeLocks::class)->lock('node-agent:id:'.$node->id, 420);
    $lock->get();
    try {
        $agents = new FakeAgentCommandRunner;
        app()->instance(AgentCommandRunner::class, $agents);
        $this->artisan('orbit:node-agent-rekey', ['node' => (string) $node->id, 'operation' => (string) $operation->id])->assertFailed();
        expect($agents->commands())->toBe([]);
    } finally {
        $lock->release();
    }
});

it('leaves the owner unchanged after another lost reply and permits explicit fresh recovery', function (): void {
    [$node, $operation] = uncertainNodeAgent($this);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('agent.configure', function (): array {
        throw new RuntimeException('private-output-that-must-not-be-printed');
    });
    app()->instance(AgentCommandRunner::class, $agents);
    $this->artisan('orbit:node-agent-rekey', ['node' => (string) $node->id, 'operation' => (string) $operation->id])
        ->expectsOutputToContain('did not return a proven result')->doesntExpectOutputToContain('private-output-that-must-not-be-printed')->assertFailed();
    expect($node->fresh()->agent_secret_hash)->toBe(str_repeat('a', 64))
        ->and($operation->fresh()->state)->not->toHaveKey('agent_secret_rekey');
    $agents->answer('agent.configure', ['secret_hash' => str_repeat('b', 64), 'restarted' => true, 'unclosed' => []]);
    $this->artisan('orbit:node-agent-rekey', ['node' => (string) $node->id, 'operation' => (string) $operation->id])->assertSuccessful();
    expect($node->fresh()->agent_secret_hash)->toBe(str_repeat('b', 64));
});

it('repairs an already bootstrapped enrollment Node before activation', function (): void {
    [$node, $operation] = uncertainNodeAgent($this);
    $node->update(['status' => 'provisioning']);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('agent.configure', ['secret_hash' => str_repeat('c', 64), 'restarted' => true, 'unclosed' => []]);
    app()->instance(AgentCommandRunner::class, $agents);
    $this->artisan('orbit:node-agent-rekey', ['node' => (string) $node->id, 'operation' => (string) $operation->id])->assertSuccessful();
    expect($node->fresh()->agent_secret_hash)->toBe(str_repeat('c', 64));
});

it('uses the same-target enrollment ancestor hold and refuses a changed child snapshot', function (bool $tamper): void {
    [$node, $operation] = uncertainNodeAgent($this);
    $operation->update(['lock_key' => null]);
    $intent = ['key' => 'enrollment-footprint', 'kind' => 'node.converge', 'node_id' => $node->id,
        'input' => ['force_artifacts' => false, 'first_visit' => false]];
    $parent = Operation::query()->create(['kind' => 'node.add', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'input' => [], 'state' => ['child_intents' => [$intent]],
        'status' => 'running', 'lock_key' => 'node:'.$node->id, 'sequence' => 1, 'attempts' => 1]);
    $snapshot = ['intent' => $intent, 'admitted' => ['parent_operation_id' => $parent->id, 'key' => $intent['key'],
        'kind' => 'node.converge', 'node_id' => $node->id, 'target_type' => 'node', 'target_id' => $node->id,
        'input' => $operation->input, 'run_constraints' => []]];
    $operation->forceFill(['parent_operation_id' => $parent->id, 'child_key' => $intent['key'],
        'child_admission' => $snapshot, 'child_fingerprint' => OperationChildIntent::fingerprint($snapshot)])->save();
    if ($tamper) {
        $operation->forceFill(['child_key' => 'foreign-child'])->save();
    }
    $agents = new FakeAgentCommandRunner;
    $agents->answer('agent.configure', ['secret_hash' => str_repeat('d', 64), 'restarted' => true, 'unclosed' => []]);
    app()->instance(AgentCommandRunner::class, $agents);
    $this->artisan('orbit:node-agent-rekey', ['node' => (string) $node->id, 'operation' => (string) $operation->id])
        ->assertExitCode($tamper ? 1 : 0);
    expect($agents->commands())->toBe($tamper ? [] : ['agent.configure'])
        ->and($parent->fresh()->lock_key)->toBe('node:'.$node->id);
})->with([false, true]);

it('does not accept malformed hashes or a reply without restart proof', function (bool $badHash): void {
    [$node, $operation] = uncertainNodeAgent($this);
    $agents = new FakeAgentCommandRunner;
    $agents->answerMalformed('agent.configure', ['secret_hash' => $badHash ? 'invalid' : str_repeat('e', 64),
        'restarted' => $badHash, 'unclosed' => []]);
    app()->instance(AgentCommandRunner::class, $agents);
    $this->artisan('orbit:node-agent-rekey', ['node' => (string) $node->id, 'operation' => (string) $operation->id])->assertFailed();
    expect($node->fresh()->agent_secret_hash)->toBe(str_repeat('a', 64))
        ->and($operation->fresh()->state)->not->toHaveKey('agent_secret_rekey');
})->with([true, false]);

it('restores executor authentication without claiming or waiting for Swamp', function (string $peer): void {
    [$node, $operation] = uncertainNodeAgent($this);
    config($peer === 'services'
        ? ['dependency_detection.services_node_id' => (string) $node->id]
        : ['operation_executor.worker_node_ids' => [$node->id]]);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('agent.configure', ['secret_hash' => str_repeat('f', 64), 'restarted' => true, 'unclosed' => []]);
    app()->instance(AgentCommandRunner::class, $agents);
    $this->artisan('orbit:node-agent-rekey', ['node' => (string) $node->id, 'operation' => (string) $operation->id])->assertSuccessful();
    expect($node->fresh()->agent_secret_hash)->toBe(str_repeat('f', 64))
        ->and($operation->fresh()->state['executor_runs']['footprint-agent']['result'])->toBeNull();
})->with(['services', 'worker']);
