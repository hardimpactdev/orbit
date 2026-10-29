<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationRequestFingerprint;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Node;
use App\Models\Operation;

beforeEach(function (): void {
    $make = static fn (string $name, string $ip): Node => Node::query()->create(['name' => $name, 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'wireguard_ip' => $ip, 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $this->gateway = $make('gateway', '10.44.0.1');
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->caller = $make('operator', '10.44.0.2');
    $this->serving = $make('dev', '10.44.0.3');
    $this->caller->accessibleNodes()->attach($this->serving);
    $fingerprint = app(OperationRequestFingerprint::class)->make($this->caller, 'route:destroy', ['route' => '999'], '[]', new StartsOperation('route.destroy'));
    $this->operation = Operation::query()->create(['kind' => 'route.destroy', 'target_type' => 'route', 'target_id' => 999, 'node_id' => $this->serving->id,
        'input' => ['route_id' => 999, 'access_node_ids' => [$this->serving->id], 'public_process' => false], 'status' => OperationStatus::Succeeded, 'sequence' => 4,
        'idempotency_key' => 'destroy-proof', 'idempotency_fingerprint' => $fingerprint]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])->withHeader('Idempotency-Key', 'destroy-proof');
});

it('replays removal after Route deletion only while the caller still has access', function (): void {
    $this->deleteJson('/api/v1/routes/999')->assertAccepted()->assertJsonPath('data.id', $this->operation->id);
    $this->caller->accessibleNodes()->detach($this->serving);
    $this->deleteJson('/api/v1/routes/999')->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
});

it('rejects another target, changed input and a missing retry key', function (): void {
    $this->deleteJson('/api/v1/routes/999', ['offline' => true])->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $this->deleteJson('/api/v1/routes/998')->assertNotFound();
    $this->withoutHeader('Idempotency-Key')->deleteJson('/api/v1/routes/999')->assertNotFound();
});

it('requires both public Process edges on a deleted Route replay', function (): void {
    $ingress = Node::query()->create(['name' => 'ingress', 'public_ssh_host' => '192.0.2.4', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.4', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $this->operation->update(['input' => ['route_id' => 999, 'access_node_ids' => [$this->serving->id, $ingress->id], 'public_process' => true]]);
    $this->deleteJson('/api/v1/routes/999')->assertForbidden();
    $this->caller->accessibleNodes()->attach($ingress);
    $this->deleteJson('/api/v1/routes/999')->assertAccepted()->assertJsonPath('data.id', $this->operation->id);
});

it('requires Gateway authority when the recorded serving Node no longer exists', function (): void {
    $this->serving->delete();
    $this->deleteJson('/api/v1/routes/999')->assertForbidden();
    $this->caller->accessibleNodes()->attach($this->gateway);
    $this->deleteJson('/api/v1/routes/999')->assertAccepted()->assertJsonPath('data.id', $this->operation->id);
});
