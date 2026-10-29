<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use Tests\Support\Fleet\FakeFootprintArtifact;
use Tests\Support\Fleet\FleetFixtures;

it('converges executor Nodes synchronously without admitting or waiting for an Operation', function (string $peer, bool $force): void {
    $artifact = new FakeFootprintArtifact('agent', 'current');
    FleetFixtures::bind([$artifact]);
    $gateway = FleetFixtures::node('sync-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('executor-'.$peer);
    config($peer === 'services'
        ? ['dependency_detection.services_node_id' => (string) $node->id]
        : ['operation_executor.worker_node_ids' => [(string) $node->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $path = '/api/v1/nodes/'.$node->id.'/converge';
    $this->postJson($path)->assertOk()->assertJsonPath('data.artifacts.agent', 'applied')->assertJsonMissingPath('data.kind');
    $artifact->applied = [];
    $this->postJson($path, ['force' => $force])->assertOk()->assertJsonPath('data.changed', $force);
    expect($artifact->applied)->toBe($force ? [$node->name] : []);
    $this->assertDatabaseCount('operations', 0);
})->with(['services', 'worker'])->with([false, true]);
