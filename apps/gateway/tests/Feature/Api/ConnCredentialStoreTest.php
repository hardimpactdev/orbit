<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Domain\ConnPairingCredentialStore;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'pairing-owner', 'status' => 'active', 'public_ssh_host' => '192.0.2.19']);
    $environment = ConnEnvironment::query()->create(['environment_id' => 'credential-proof', 'label' => 'dev', 'node_id' => $this->node->id,
        'url' => 'http://dev.test', 'admin_session' => 'fixture-admin', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
    $this->pairing = ConnPairing::query()->create(['node_id' => $this->node->id, 'conn_environment_id' => $environment->id,
        'client_label' => 'dev proof', 'pairing_link_id' => 'one-time-link', 'issue_started_at' => now()]);
    $this->operation = new Operation(['kind' => 'conn.pairing_create', 'target_type' => 'conn_pairing', 'target_id' => $this->pairing->id,
        'node_id' => $this->node->id, 'input' => ['pairing_ids' => [$this->pairing->id], 'caller_node_id' => $this->node->id, 'conn_environment_id' => $environment->id]]);
});

it('stores only the pairing owner credential encrypted and supports the same value replay', function (): void {
    $store = app(ConnPairingCredentialStore::class);
    DB::transaction(fn () => $store->store($this->operation, $this->pairing->id, 'one-time-sentinel'));
    DB::transaction(fn () => $store->store($this->operation, $this->pairing->id, 'one-time-sentinel'));
    expect($this->pairing->fresh()->credential)->toBe('one-time-sentinel')
        ->and(DB::table('conn_pairings')->where('id', $this->pairing->id)->value('credential'))->not->toContain('one-time-sentinel');
    expect(json_encode($this->pairing->fresh()->toArray()))->not->toContain('one-time-sentinel');
    expect(fn () => $store->store($this->operation, $this->pairing->id, 'different-value'))->toThrow(ResourceOperationException::class);
});

it('refuses a foreign caller, environment, target type and revoked owner before storing', function (): void {
    $store = app(ConnPairingCredentialStore::class);
    foreach (['caller_node_id', 'conn_environment_id'] as $field) {
        $other = clone $this->operation;
        $input = $other->input;
        $input[$field]++;
        $other->input = $input;
        expect($store->ownerId($other))->toBeNull();
        expect(fn () => $store->store($other, $this->pairing->id, 'foreign-value'))->toThrow(ResourceOperationException::class);
    }
    $other = clone $this->operation;
    $other->target_type = 'node';
    expect($store->ownerId($other))->toBeNull();
    $this->pairing->update(['revoked_at' => now()]);
    expect($store->ownerId($this->operation))->toBeNull()
        ->and($this->pairing->fresh()->credential)->toBeNull();
});

it('refuses empty and non-scalar secret outputs', function (mixed $credential): void {
    expect(fn () => app(ConnPairingCredentialStore::class)->store($this->operation, $this->pairing->id, $credential))->toThrow(ResourceOperationException::class);
    expect($this->pairing->fresh()->credential)->toBeNull();
})->with(['empty' => [''], 'object' => [['token' => 'secret']], 'null' => [null]]);
