<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeSettingsOperationCompletion;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    app()->instance(OperationKinds::class, new OperationKinds(app(), ['node.remove' => [
        'executor' => 'swamp', 'completion' => NodeSettingsOperationCompletion::class, 'runs' => [],
        'completion_contract' => ['step' => 'intent-only', 'transport' => 'gateway', 'name' => 'intent-only', 'result' => []], 'result_fields' => ['error_code'],
    ]]));
});

it('returns recorded removal intent and replays the same Operation before any cleanup', function (): void {
    $caller = FleetFixtures::node('removal-http-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('removal-http-source');
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->withHeader('Idempotency-Key', 'removal-http-retry');
    $response = $this->deleteJson('/api/v1/nodes/'.$node->id, ['force' => true])->assertAccepted()
        ->assertJsonPath('data.kind', 'node.remove')->assertJsonPath('data.status', 'queued');
    $id = $response->json('data.id');

    $this->deleteJson('/api/v1/nodes/'.$node->id, ['force' => true])->assertAccepted()->assertJsonPath('data.id', $id);

    expect(Operation::query()->where('kind', 'node.remove')->count())->toBe(1);
    $this->assertDatabaseHas('nodes', ['id' => $node->id, 'status' => 'active']);
});

it('keeps exact removal flags and requires offline consent before admission', function (array $input): void {
    $caller = FleetFixtures::node('removal-flags-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('removal-flags-source');

    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->deleteJson('/api/v1/nodes/'.$node->id, $input)
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

    expect(Operation::query()->count())->toBe(0);
    $this->assertModelExists($node);
})->with([[['offline' => true]], [['force' => 'true']], [['offline' => 1]], [['host' => '192.0.2.3']]]);

it('requires access to the departing Node', function (): void {
    FleetFixtures::node('removal-access-gateway', [RoleName::Gateway]);
    $caller = FleetFixtures::node('removal-access-caller');
    $node = FleetFixtures::node('removal-access-source');

    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->deleteJson('/api/v1/nodes/'.$node->id, ['force' => true])->assertForbidden();

    expect(Operation::query()->count())->toBe(0);
});
