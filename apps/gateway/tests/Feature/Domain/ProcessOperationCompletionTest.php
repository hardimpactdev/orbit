<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessOperationCompletion;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

it('refuses an unsettled systemd backup even with proven successful command replies', function (): void {
    $node = Node::query()->create(['name' => 'backup-proof', 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.3']);
    $stage = ['staged' => true, 'previous' => true, 'state' => null];
    $activation = ['backup_left' => true];
    $operation = Operation::query()->create(['kind' => 'process.update', 'target_type' => 'process', 'target_id' => 12, 'node_id' => $node->id, 'status' => 'running',
        'input' => ['runtime' => 'systemd', 'desired' => 'stopped', 'activation_pending' => false],
        'state' => ['steps' => ['process-stage-unit' => $stage, 'process-activate-unit' => $activation], 'executor_runs' => [
            'process-stage-unit' => ['transport' => 'agent', 'name' => 'process.stage-unit', 'result' => $stage],
            'process-activate-unit' => ['transport' => 'agent', 'name' => 'process.activate-unit', 'result' => $activation],
        ]]]);
    foreach (['process-stage-unit', 'process-activate-unit'] as $index => $name) {
        $operation->steps()->create(['sequence' => $index + 1, 'position' => $index + 1, 'name' => $name, 'status' => 'succeeded', 'resumable' => true]);
    }
    expect(fn () => app(ProcessOperationCompletion::class)->assertEffects($operation))->toThrow(ResourceOperationException::class);
});
