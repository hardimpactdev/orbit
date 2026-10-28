<?php

declare(strict_types=1);

use App\Domain\Integrations\IntegrationRoleIntent;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketPublicationStateCommand;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Infrastructure\WebSocket\WebSocketFootprint;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Setting;
use App\Models\WebSocketRuntime;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->source = Node::query()->create(['name' => 'old', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.19', 'public_ssh_host' => '192.0.2.19']);
    $this->target = Node::query()->create(['name' => 'new', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.20', 'public_ssh_host' => '192.0.2.20']);
    $this->retained = ['assignment_id' => 77, 'role' => 'websocket', 'source_node_id' => $this->source->id, 'target_node_id' => $this->target->id,
        'created' => false, 'role_configuration' => ['cluster_id' => null, 'source_settings' => ['scope_type' => 'node', 'scope_id' => $this->source->id],
            'target_settings' => ['scope_type' => 'node', 'scope_id' => $this->target->id]]];
    $this->parent = Operation::query()->create(['kind' => 'node.role_relocate', 'target_type' => 'node', 'target_id' => $this->target->id,
        'node_id' => $this->target->id, 'status' => 'running', 'input' => [], 'state' => ['role_intent' => $this->retained]]);
});

it('resolves source cleanup through retained parent intent after the source assignment moved', function (): void {
    $child = new Operation(['kind' => 'websocket.role_remove', 'node_id' => $this->source->id, 'input' => ['purge_data' => false, 'offline' => false]]);
    $child->forceFill(['parent_operation_id' => $this->parent->id]);
    $intent = app(IntegrationRoleIntent::class)->get($child);
    expect($intent)->toMatchArray(['assignment_id' => 77, 'source_node_id' => $this->source->id, 'target_node_id' => $this->target->id, 'created' => false]);
    expect($this->source->roles()->count())->toBe(0);
});

it('binds convergence to the retained target Node and refuses a foreign Settings scope', function (): void {
    $child = new Operation(['kind' => 'websocket.role_converge', 'node_id' => $this->target->id, 'input' => []]);
    $child->forceFill(['parent_operation_id' => $this->parent->id]);
    expect(app(IntegrationRoleIntent::class)->get($child)['assignment_id'])->toBe(77);
    $retained = $this->retained;
    $retained['role_configuration']['target_settings']['scope_id'] = $this->source->id;
    $this->parent->update(['state' => ['role_intent' => $retained]]);
    expect(fn () => app(IntegrationRoleIntent::class)->get($child))->toThrow(ResourceOperationException::class);
});

it('refuses another Node or integration family before reading any scoped Settings', function (): void {
    foreach ([['websocket.role_remove', $this->target->id], ['metrics.role_remove', $this->source->id]] as [$kind, $node]) {
        $child = new Operation(['kind' => $kind, 'node_id' => $node, 'input' => ['purge_data' => false, 'offline' => false]]);
        $child->forceFill(['parent_operation_id' => $this->parent->id]);
        expect(fn () => app(IntegrationRoleIntent::class)->get($child))->toThrow(ResourceOperationException::class);
    }
});

it('retires only the managed process online and every unreachable runtime offline while keeping Settings', function (bool $offline): void {
    app(WebSocketCredentialManager::class)->ensure($this->source);
    $other = WebSocketRuntime::query()->create(['id' => (string) Str::uuid(), 'node_id' => $this->source->id, 'slot' => 'other',
        'registry_token' => 'fixture-token', 'notification_token' => 'fixture-notification', 'required' => true]);
    app(WebSocketDnsTarget::class)->markServing($this->source->id);
    $operation = new Operation(['kind' => 'websocket.role_remove', 'node_id' => $this->source->id, 'input' => ['purge_data' => false, 'offline' => $offline]]);
    expect(app(WebSocketPublicationStateCommand::class)->run($operation, ['action' => 'retired']))->toBe(['recorded' => true]);
    expect(WebSocketRuntime::query()->where('node_id', $this->source->id)->where('slot', 'default')->sole()->required)->toBeFalse()
        ->and($other->fresh()->required)->toBe(! $offline)
        ->and(app(WebSocketDnsTarget::class)->isServing($this->source->id))->toBeFalse();
    expect(Setting::query()->where('scope_id', $this->source->id)->where('key', WebSocketFootprint::SettingKeyAppKeyLaravel)->exists())->toBeTrue();
})->with([false, true]);
