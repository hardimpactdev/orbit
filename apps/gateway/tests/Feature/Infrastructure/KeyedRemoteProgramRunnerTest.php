<?php

declare(strict_types=1);

use App\Domain\Operations\EffectReceiptMetadata;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgram;
use App\Domain\RemotePrograms\RemoteProgramLanguage;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
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
    $receipt = ['command' => 'remote_program.test.program', 'effect_key' => 'op-42.effect', 'state' => 'succeeded', 'sanitized' => true, 'effect_nonce' => '11111111-1111-4111-8111-111111111111', 'effect_created_at' => 1793224800, 'exit_code' => 0,
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
    expect($runner->run($node, $program, ['value' => 'input'], 'op-42.effect', effect: new EffectReceiptMetadata([], ['nonce' => '11111111-1111-4111-8111-111111111111', 'created_at' => 1793224800]))->data)->toBe(['ready' => true]);
    expect($runner->run($node, $program, ['value' => 'input'], 'op-42.effect', effect: new EffectReceiptMetadata([], ['nonce' => '11111111-1111-4111-8111-111111111111', 'created_at' => 1793224800]))->data)->toBe(['ready' => true]);
    expect($runner->receipt($node, $program, 'op-42.effect')['result'])->toBe($response);
});

it('does not execute a fixed remote program when the shared reservation has an unfinished result', function (string $state): void {
    $node = Node::query()->create(['name' => 'dev', 'user' => 'orbit', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.3']);
    $program = new RemoteProgram('test.program', RemoteProgramLanguage::Python, '# fixed', ['succeeded'], 60);
    $receipt = ['command' => 'remote_program.test.program', 'effect_key' => 'op-42.effect', 'state' => $state, 'sanitized' => false, 'effect_nonce' => '11111111-1111-4111-8111-111111111111', 'effect_created_at' => 1793224800, 'exit_code' => null, 'result' => null, 'result_digest' => null, 'finished_at' => null];
    $agent = (new FakeAgentCommandRunner)->answer('agent.effect-reserve', ['reserved' => false, 'receipt' => $receipt]);
    $remote = Mockery::mock(RemoteProgramRunner::class);
    $remote->shouldNotReceive('run');
    $runner = new KeyedRemoteProgramRunner($remote, $agent, new AgentEffectReceipts($agent));
    expect(fn () => $runner->run($node, $program, [], 'op-42.effect', effect: new EffectReceiptMetadata([], ['nonce' => '11111111-1111-4111-8111-111111111111', 'created_at' => 1793224800])))->toThrow(ResourceOperationException::class, 'cannot be repeated');
})->with(['unknown', 'running']);

it('passes the server key into native command input and reads the saved native outcome', function (): void {
    $node = Node::query()->create(['name' => 'dev', 'user' => 'orbit', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.3']);
    $operation = new Operation(['node_id' => $node->id]);
    $input = ['operation' => 'restart', 'process_id' => 42, 'runtime' => 'systemd', 'name' => 'orbit-process-42.service', 'on_demand' => false];
    $run = ['worker' => 'worker', 'key' => 'effect', 'transport' => 'agent', 'name' => 'process.control', 'input' => $input, 'effect_key' => 'op-42.effect', 'effect_metadata' => new EffectReceiptMetadata([], ['nonce' => '11111111-1111-4111-8111-111111111111', 'created_at' => 1793224800])];
    $agent = (new FakeAgentCommandRunner)->answer('process.control', [])->answer('agent.effect-receipt', [
        'command' => 'process.control', 'effect_key' => 'op-42.effect', 'state' => 'succeeded', 'sanitized' => true, 'effect_nonce' => '11111111-1111-4111-8111-111111111111', 'effect_created_at' => 1793224800, 'exit_code' => 0,
        'result' => ['ok' => true, 'data' => []], 'result_digest' => str_repeat('a', 64), 'finished_at' => '2026-10-28T20:00:00Z',
    ]);
    $transport = new OperationAgentTransport($agent, app(AgentContract::class), new AgentEffectReceipts($agent));
    expect($transport->run($operation, $run, $input))->toBe([]);
    expect($agent->calls[0]['input']['effect_key'])->toBe('op-42.effect');
    expect($transport->receipt($operation, $run, $input)['result'])->toBe([]);
    expect($agent->calls[1]['node'])->toBe($node->name)->and($agent->calls[1]['input']['command'])->toBe('process.control');
});

it('keeps a program result uncertain when effect completion refuses after the program ran', function (string $code): void {
    $node = Node::query()->create(['name' => 'dev', 'user' => 'orbit', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.3']);
    $program = new RemoteProgram('test.program', RemoteProgramLanguage::Python, '# fixed', ['succeeded'], 60);
    $identity = ['nonce' => '11111111-1111-4111-8111-111111111111', 'created_at' => 1793224800];
    $agent = (new FakeAgentCommandRunner)->answer('agent.effect-reserve', ['reserved' => true, 'receipt' => [
        'command' => 'remote_program.test.program', 'effect_key' => 'op-42.effect', 'state' => 'running', 'sanitized' => false,
        'effect_nonce' => $identity['nonce'], 'effect_created_at' => $identity['created_at'],
        'exit_code' => null, 'result' => null, 'result_digest' => null, 'finished_at' => null,
    ]])->answer('agent.effect-complete', static fn (): array => throw new AgentCommandRefused('agent.effect-complete', $code, 'Receipt could not be saved.'));
    $remote = Mockery::mock(RemoteProgramRunner::class);
    $remote->shouldReceive('run')->once()->andReturn(new ProgramResult('succeeded', ['ready' => true], ''));
    $runner = new KeyedRemoteProgramRunner($remote, $agent, new AgentEffectReceipts($agent));
    $effect = new EffectReceiptMetadata([], $identity);
    try {
        $runner->run($node, $program, [], 'op-42.effect', effect: $effect);
        test()->fail('The missing receipt must remain uncertain.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('operation.execution_uncertain');
    }
    expect($effect->receipt())->toBe([]);
})->with(['agent.deadline_exceeded', 'agent.effect_receipt_unavailable', 'program.command_refused']);

it('preserves failed state and exit code in live protected metadata', function (): void {
    $node = Node::query()->create(['name' => 'dev', 'user' => 'orbit', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.3']);
    $program = new RemoteProgram('test.program', RemoteProgramLanguage::Python, '# fixed', ['succeeded', 'failed'], 60);
    $identity = ['nonce' => '11111111-1111-4111-8111-111111111111', 'created_at' => 1793224800];
    $receipt = ['command' => 'remote_program.test.program', 'effect_key' => 'op-42.effect', 'state' => 'failed', 'sanitized' => true,
        'effect_nonce' => $identity['nonce'], 'effect_created_at' => $identity['created_at'], 'exit_code' => 23,
        'result' => ['ok' => true, 'data' => ['outcome' => 'failed', 'data' => ['exit_code' => 23], 'log' => '']],
        'result_digest' => str_repeat('a', 64), 'finished_at' => '2026-10-28T20:00:00Z'];
    $agent = (new FakeAgentCommandRunner)->answer('agent.effect-reserve', ['reserved' => true, 'receipt' => [...$receipt,
        'state' => 'running', 'exit_code' => null, 'result' => null, 'result_digest' => null, 'finished_at' => null,
    ]])->answer('agent.effect-complete', $receipt)->answer('agent.effect-receipt', $receipt);
    $remote = Mockery::mock(RemoteProgramRunner::class);
    $remote->shouldReceive('run')->once()->andReturn(new ProgramResult('failed', ['exit_code' => 23], ''));
    $runner = new KeyedRemoteProgramRunner($remote, $agent, new AgentEffectReceipts($agent));
    $effect = new EffectReceiptMetadata([], $identity);
    expect($runner->run($node, $program, [], 'op-42.effect', effect: $effect)->outcome)->toBe('failed');
    $recovered = $runner->receipt($node, $program, 'op-42.effect');
    expect($effect->receipt()['state'])->toBe($recovered['state'])->toBe('failed');
    expect($effect->receipt()['exit_code'])->toBe($recovered['exit_code'])->toBe(23);
});

it('keeps only originally protected booleans in transient typed masks', function (): void {
    $metadata = EffectReceiptMetadata::hydrated(['1'], ['nonce' => '11111111-1111-4111-8111-111111111111', 'created_at' => 1793224800],
        ['secret' => ['integer' => 1, 'text' => 'true', 'boolean' => false], 'public' => true], ['secret']);
    expect($metadata->envelope()['booleans'])->toBe([false]);
    expect(json_encode($metadata))->toBe('"[PROTECTED]"');
    $numeric = EffectReceiptMetadata::hydrated(['1'], $metadata->identity(), ['secret' => 1, 'public' => true], ['secret']);
    expect($numeric->envelope()['booleans'])->toBe([]);
});
