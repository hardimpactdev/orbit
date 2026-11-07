<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '10.44.0.1', 'user' => 'orbit',
    ]));
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->server = DatabaseServer::query()->create([
        'slug' => 'adopted-valkey', 'driver' => 'redis', 'node_id' => $this->gateway->id,
        'tag' => '8.1', 'port' => 6379, 'status' => 'active', 'default_open' => true,
        'root_password' => 'database-update-secret-sentinel',
    ]);
    $this->path = '/api/v1/database-servers/adopted-valkey';
    app()->instance(SwampDispatch::class, new class
    {
        public function start(Operation $operation): void {}
    });
});

it('requires an explicit JSON true before recording any intent', function (array $body): void {
    $this->patchJson($this->path, $body)->assertUnprocessable();
    expect(Operation::query()->count())->toBe(0)
        ->and(DatabaseExecutionIntent::query()->count())->toBe(0)
        ->and($this->server->refresh()->default_open)->toBeTrue();
})->with([
    'missing' => [[]], 'false' => [['require_password' => false]],
    'string' => [['require_password' => 'true']], 'number' => [['require_password' => 1]],
    'null' => [['require_password' => null]],
    'other setting' => [['require_password' => true, 'default_open' => true]],
]);

it('records the unchanged close kind and selects close or readiness', function (bool $open, string $action): void {
    $this->server->update(['default_open' => $open]);
    $response = $this->withHeader('Idempotency-Key', 'password-update-start')
        ->patchJson($this->path, ['require_password' => true])
        ->assertAccepted()->assertJsonPath('data.kind', 'database_server.close');
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $intent = DatabaseExecutionIntent::recorded($operation->input['intent_id']);
    expect($intent->payload['commands']['database-apply']['input']['action'])->toBe($action)
        ->and($intent->payload['projection'])->toBe(['server' => ['default_open' => false]])
        ->and($operation->state['swamp_start']['workflow'])->toBe('operation-database-server-close')
        ->and($response->getContent())->not->toContain('database-update-secret-sentinel');
    $this->patchJson($this->path, ['require_password' => true])->assertAccepted()
        ->assertJsonPath('data.id', $operation->id);
    expect(Operation::query()->count())->toBe(1)->and(DatabaseExecutionIntent::query()->count())->toBe(1);
})->with([[true, 'close'], [false, 'ready']]);

it('keeps the driver lifecycle and connection password guards', function (string $guard, string $code, int $status): void {
    match ($guard) {
        'driver' => $this->server->update(['driver' => 'mysql']),
        'inactive' => $this->server->update(['status' => 'failed']),
        'password' => DatabaseConnection::query()->create([
            'slug' => 'passwordless', 'driver' => 'redis', 'node_id' => $this->gateway->id,
            'host' => '10.44.0.1', 'port' => 6379, 'database_server_id' => $this->server->id,
        ]),
    };
    $this->patchJson($this->path, ['require_password' => true])->assertStatus($status)->assertJsonPath('error.code', $code);
    expect(Operation::query()->count())->toBe(0)->and(DatabaseExecutionIntent::query()->count())->toBe(0);
})->with([
    ['driver', 'database.driver_unsupported', 422],
    ['inactive', 'database.server_inactive', 409],
    ['password', 'database.server_in_use', 409],
]);

it('removes the old close route', function (): void {
    $this->postJson($this->path.'/close')->assertNotFound();
    expect(app('router')->getRoutes()->getByName('database:server:close'))->toBeNull();
});
