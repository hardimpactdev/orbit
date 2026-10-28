<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeSettingsOperationCompletion;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    app()->instance(OperationKinds::class, new OperationKinds(app(), array_fill_keys(['node.role_add', 'node.role_remove', 'node.role_relocate'], [
        'executor' => 'swamp', 'completion' => NodeSettingsOperationCompletion::class, 'runs' => [],
        'completion_contract' => ['step' => 'intent-only', 'transport' => 'gateway', 'name' => 'intent-only', 'result' => []], 'result_fields' => ['error_code'],
    ])));
});

it('returns 202 and the same retained assignment on an idempotent retry', function (): void {
    $caller = FleetFixtures::node('role-http-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('role-http-target', [RoleName::AppDev]);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->withHeader('Idempotency-Key', 'role-http-retry');
    $response = $this->postJson("/api/v1/nodes/{$node->id}/roles", ['role' => 'websocket'])->assertAccepted()->assertJsonPath('data.kind', 'node.role_add');
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $assignment = NodeRole::query()->findOrFail($operation->state['role_intent']['assignment_id']);
    $assignment->markConvergenceFailed('publication', 'websocket.publication_failed');

    $this->postJson("/api/v1/nodes/{$node->id}/roles", ['role' => 'websocket'])->assertAccepted()->assertJsonPath('data.id', $operation->id);

    expect($operation->state['role_intent']['created'])->toBeTrue();
    expect($node->roles()->where('role', RoleName::WebSocket)->count())->toBe(1);
});

it('keeps role removal as recorded intent until Swamp proves it', function (): void {
    $caller = FleetFixtures::node('remove-role-http-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('remove-role-http-target', [RoleName::WebSocket]);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);

    $this->deleteJson("/api/v1/nodes/{$node->id}/roles/websocket", ['force' => true])->assertAccepted()->assertJsonPath('data.kind', 'node.role_remove');

    $this->assertDatabaseHas('node_roles', ['node_id' => $node->id, 'role' => 'websocket', 'status' => 'removing']);
});

it('requires Gateway access for Metrics but target access for ordinary role changes', function (string $role, int $status): void {
    FleetFixtures::node('role-access-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('role-access-target', [RoleName::AppDev]);
    $caller = FleetFixtures::node('role-access-caller');
    $caller->accessibleNodes()->attach($node);

    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson("/api/v1/nodes/{$node->id}/roles", ['role' => $role])->assertStatus($status);

    expect($node->roles()->where('role', $role)->exists())->toBe($status === 202);
})->with([['metrics', 403], ['websocket', 202]]);

it('preserves protected-role and exact boolean validation before admission', function (array $input): void {
    $caller = FleetFixtures::node('role-validation-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('role-validation-target', [RoleName::AppDev]);

    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson("/api/v1/nodes/{$node->id}/roles", $input)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

    expect(Operation::query()->count())->toBe(0);
})->with([
    [['role' => 'vpn']], [['role' => 'router']], [['role' => 'unknown']],
    [['role' => 'websocket', 'converge_existing' => 'true']],
    [['role' => 'websocket', 'postgres_process_id' => 12]], [['role' => 'analytics']],
]);

it('requires explicit removal consent and leaves the assignment active', function (array $input): void {
    $caller = FleetFixtures::node('role-consent-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('role-consent-target', [RoleName::WebSocket]);

    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$node->id}/roles/websocket", $input)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

    $this->assertDatabaseHas('node_roles', ['node_id' => $node->id, 'role' => 'websocket', 'status' => 'active']);
    expect(Operation::query()->count())->toBe(0);
})->with([['input' => []], ['input' => ['force' => false]], ['input' => ['offline' => true]], ['input' => ['purge_data' => true]], ['input' => ['force' => 'true']]]);

it('records relocation while the original source still owns the assignment', function (): void {
    $caller = FleetFixtures::node('relocate-role-http-gateway', [RoleName::Gateway]);
    $source = FleetFixtures::node('relocate-role-http-source', [RoleName::WebSocket]);
    $target = FleetFixtures::node('relocate-role-http-target', [RoleName::AppDev]);
    $assignment = $source->roles->sole();

    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson("/api/v1/nodes/{$target->id}/roles/websocket/relocate", ['force' => true])->assertAccepted()->assertJsonPath('data.kind', 'node.role_relocate');

    expect($assignment->refresh()->node_id)->toBe($source->id);
    expect(Operation::query()->sole()->state['role_intent'])->toMatchArray(['assignment_id' => $assignment->id, 'created' => false,
        'source_node_id' => $source->id, 'target_node_id' => $target->id]);
});

it('lists assignments in catalog order with retained failure details', function (): void {
    $caller = FleetFixtures::node('list-role-http-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('list-role-http-target', [RoleName::AppProd, RoleName::Vpn, RoleName::AppDev]);
    $failed = $node->roles()->where('role', RoleName::AppProd)->sole();
    $failed->update(['status' => 'failed', 'failed_step' => 'converge:packages', 'error_code' => 'packages.failed']);

    $response = $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->getJson("/api/v1/nodes/{$node->id}/roles")->assertOk();

    expect(array_column($response->json('data'), 'role'))->toBe(['vpn', 'app-dev', 'app-prod']);
    $response->assertJsonPath('data.2.failed_step', 'converge:packages')->assertJsonPath('data.2.error_code', 'packages.failed');
});
