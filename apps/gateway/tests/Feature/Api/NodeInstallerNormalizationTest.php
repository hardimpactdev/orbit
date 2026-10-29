<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\ManagedAgentInstallOperationTransport;
use App\Infrastructure\Nodes\NodeOperationAgentInstaller;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

function installerNormalizationReceipt(array $result): array
{
    return ['effect_key' => 'op-7.agent-install', 'state' => 'succeeded', 'result' => $result,
        'sanitized' => true, 'effect_nonce' => '8d66ea16-8f73-44b1-a501-211a3e41cd78', 'effect_created_at' => 1793232000,
        'result_digest' => str_repeat('b', 64), 'exit_code' => 0, 'finished_at' => '2026-10-29T00:00:00Z'];
}

it('preserves internal receipt identity when normalizing a managed installer result', function (): void {
    $node = FleetFixtures::node('managed-normalizer');
    $operation = (new Operation)->forceFill(['id' => 7, 'node_id' => $node->id, 'input' => ['_footprint' => ['agent_pin' => str_repeat('a', 64)]]]);
    $original = installerNormalizationReceipt(['replaced' => true]);
    $installer = Mockery::mock(NodeOperationAgentInstaller::class);
    $installer->shouldReceive('effectReceipt')->once()->andReturn($original);

    $result = (new ManagedAgentInstallOperationTransport($installer))->receipt($operation, ['name' => 'agent.install', 'effect_key' => 'op-7.agent-install'], []);

    expect($result)->toBe([...$original, 'result' => ['installed' => true, 'changed' => true]]);
});

it('refuses a success receipt that has no original installer replacement result', function (): void {
    $node = FleetFixtures::node('managed-missing-result');
    $operation = (new Operation)->forceFill(['id' => 7, 'node_id' => $node->id, 'input' => ['_footprint' => ['agent_pin' => str_repeat('a', 64)]]]);
    $installer = Mockery::mock(NodeOperationAgentInstaller::class);
    $installer->shouldReceive('effectReceipt')->once()->andReturn(installerNormalizationReceipt([]));

    expect(fn () => (new ManagedAgentInstallOperationTransport($installer))->receipt($operation, ['name' => 'agent.install', 'effect_key' => 'op-7.agent-install'], []))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.receipt_unproven'));
});
