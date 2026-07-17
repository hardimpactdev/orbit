<?php

declare(strict_types=1);

use App\Data\Nodes\RoleSettings\WebSocketRoleSettings;

it('requires a positive valkey node id', function (): void {
    expect(WebSocketRoleSettings::fromArray(['valkey_node_id' => 12])->toArray())
        ->toBe(['valkey_node_id' => 12]);
});

it('rejects missing valkey node id', function (): void {
    expect(fn () => WebSocketRoleSettings::fromArray([]))
        ->toThrow(InvalidArgumentException::class, 'The websocket role requires a valid valkey_node_id setting.');
});

it('rejects non-positive valkey node id values', function (mixed $valkeyNodeId): void {
    expect(fn () => WebSocketRoleSettings::fromArray(['valkey_node_id' => $valkeyNodeId]))
        ->toThrow(InvalidArgumentException::class, 'The websocket role requires a valid valkey_node_id setting.');
})->with([
    'zero' => 0,
    'negative' => -1,
    'string' => '12',
]);

it('rejects unknown settings', function (): void {
    expect(fn () => WebSocketRoleSettings::fromArray(['valkey_node_id' => 12, 'host' => 'ws.example.com']))
        ->toThrow(InvalidArgumentException::class, 'The websocket role does not accept unknown settings.');
});

it('rejects the retired Redis websocket setting', function (): void {
    expect(fn () => WebSocketRoleSettings::fromArray(['redis_node_id' => 12]))
        ->toThrow(InvalidArgumentException::class, 'The websocket role does not accept unknown settings.');
});
