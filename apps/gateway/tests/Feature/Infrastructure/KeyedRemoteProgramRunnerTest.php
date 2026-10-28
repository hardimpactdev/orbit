<?php

declare(strict_types=1);

use App\Domain\Operations\EffectReceiptMetadata;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgram;
use App\Domain\RemotePrograms\RemoteProgramLanguage;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\AgentCommands\AgentEffectReceipts;
use App\Infrastructure\AgentCommands\OperationAgentTransport;
use App\Infrastructure\RemotePrograms\KeyedRemoteProgramRunner;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeAgentCommandRunner;

it('runs a fixed remote program once and returns its durable saved result on a repeated effect key', function (): void {
    $node = Node::query()->create(['name' => 'dev', 'user' => 'orbit', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.3']);
    $program = new RemoteProgram('test.program', RemoteProgramLanguage::Python, '# fixed catalog source', ['succeeded'], 60);
    $response = ['outcome' => 'succeeded', 'data' => ['ready' => true], 'log' => ''];
    $receipt = ['command' => 'remote_program.test.program', 'effect_key' => 'op-42.effect', 'state' => 'succeeded', 'sanitized' => true, 'exit_code' => 0,
        'result' => ['ok' => true, 'data' => $response], 'result_digest' => str_repeat('a', 64), 'finished_at' => '2026-10-28T20:00:00Z'];
    $reservations = 0;
    $agent = (new FakeAgentCommandRunner)->answer('agent.effect-reserve', function (array $input) use (&$reservations, $receipt): array {
        expect($input['effect_key'])->toBe('op-42.effect')->and($input['timeout_seconds'])->toBe(60);
        $reservations++;

        return ['reserved' => $reservations === 1, 'receipt' => $reservations === 1 ? [...$receipt, 'state' => 'running', 'exit_code' => null, 'result' => null, 'result_digest' => null, 'finished_at' => null] : $receipt];
    })->answer('agent.effect-complete', function (array $input) use ($receipt, $response): array {
        expect($input['result'])->toBe($response);

        return $receipt;
    })->answer('agent.effect-receipt', $receipt);
    $remote = Mockery::mock(RemoteProgramRunner::class);
    $remote->shouldReceive('run')->once()->with($node, $program, ['value' => 'input'], null, null, null)->andReturn(new ProgramResult('succeeded', ['ready' => true], ''));
    $runner = new KeyedRemoteProgramRunner($remote, $agent, new AgentEffectReceipts($agent));
    expect($runner->run($node, $program, ['value' => 'input'], 'op-42.effect', effect: new EffectReceiptMetadata([]))->data)->toBe(['ready' => true]);
    expect($runner->run($node, $program, ['value' => 'input'], 'op-42.effect', effect: new EffectReceiptMetadata([]))->data)->toBe(['ready' => true]);
    expect($runner->receipt($node, $program, 'op-42.effect')['result'])->toBe($response);
});

it('does not execute a fixed remote program when the shared reservation has an unfinished result', function (string $state): void {
    $node = Node::query()->create(['name' => 'dev', 'user' => 'orbit', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.3']);
    $program = new RemoteProgram('test.program', RemoteProgramLanguage::Python, '# fixed', ['succeeded'], 60);
    $receipt = ['command' => 'remote_program.test.program', 'effect_key' => 'op-42.effect', 'state' => $state, 'sanitized' => false, 'exit_code' => null, 'result' => null, 'result_digest' => null, 'finished_at' => null];
    $agent = (new FakeAgentCommandRunner)->answer('agent.effect-reserve', ['reserved' => false, 'receipt' => $receipt]);
    $remote = Mockery::mock(RemoteProgramRunner::class);
    $remote->shouldNotReceive('run');
    $runner = new KeyedRemoteProgramRunner($remote, $agent, new AgentEffectReceipts($agent));
    expect(fn () => $runner->run($node, $program, [], 'op-42.effect', effect: new EffectReceiptMetadata([])))->toThrow(ResourceOperationException::class, 'cannot be repeated');
})->with(['unknown', 'running']);

it('passes the server key into native command input and reads the saved native outcome', function (): void {
    $node = Node::query()->create(['name' => 'dev', 'user' => 'orbit', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.3']);
    $operation = new Operation(['node_id' => $node->id]);
    $input = ['operation' => 'restart', 'process_id' => 42, 'runtime' => 'systemd', 'name' => 'orbit-process-42.service', 'on_demand' => false];
    $run = ['worker' => 'worker', 'key' => 'effect', 'transport' => 'agent', 'name' => 'process.control', 'input' => $input, 'effect_key' => 'op-42.effect', 'effect_metadata' => new EffectReceiptMetadata([])];
    $agent = (new FakeAgentCommandRunner)->answer('process.control', [])->answer('agent.effect-receipt', [
        'command' => 'process.control', 'effect_key' => 'op-42.effect', 'state' => 'succeeded', 'sanitized' => true, 'exit_code' => 0,
        'result' => ['ok' => true, 'data' => []], 'result_digest' => str_repeat('a', 64), 'finished_at' => '2026-10-28T20:00:00Z',
    ]);
    $transport = new OperationAgentTransport($agent, app(AgentContract::class), new AgentEffectReceipts($agent));
    expect($transport->run($operation, $run, $input))->toBe([]);
    expect($agent->calls[0]['input']['effect_key'])->toBe('op-42.effect');
    expect($transport->receipt($operation, $run, $input)['result'])->toBe([]);
    expect($agent->calls[1]['node'])->toBe($node->name)->and($agent->calls[1]['input']['command'])->toBe('process.control');
});
