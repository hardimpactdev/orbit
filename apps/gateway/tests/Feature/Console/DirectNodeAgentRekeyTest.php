<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeAgentRekeyInput;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

it('refuses recovery without native configure ownership proof and preserves the Node hash', function (bool $legacyReceipt): void {
    FleetFixtures::bind();
    $node = FleetFixtures::node('rekey-owner');
    $node->forceFill(['agent_secret_hash' => str_repeat('a', 64)])->save();
    $source = Operation::query()->create([
        'kind' => 'node.converge', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'input' => [], 'status' => 'running',
        'state' => $legacyReceipt
            ? ['executor_runs' => ['footprint-agent' => ['transport' => 'agent', 'name' => 'agent.configure', 'started' => true, 'result' => null]]]
            : ['swamp_run_id' => 'ff3b7992-7376-4ac7-a49f-ed45bedc8de5'],
    ]);
    $before = $source->fresh()->toArray();
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run', 'install');
    app()->instance(AgentCommandRunner::class, $agents);

    expect(fn () => app(NodeAgentRekeyInput::class)->capture($node, $source->id))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('node.agent_rekey_unavailable')
                ->and($exception->status)->toBe(409);
        });

    expect($source->fresh()->toArray())->toBe($before);
    expect($node->fresh()->agent_secret_hash)->toBe(str_repeat('a', 64));
    expect(Operation::query()->where('kind', 'node.agent_rekey')->exists())->toBeFalse();
})->with(['native run' => false, 'obsolete Kit receipt' => true]);
