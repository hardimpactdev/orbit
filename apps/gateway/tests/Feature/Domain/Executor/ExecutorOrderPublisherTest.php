<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Executor\ExecutorOrderBroadcast;
use App\Domain\Executor\ExecutorOrderPublisher;
use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Models\Operation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Event::fake([ExecutorOrderBroadcast::class]);
    app()->forgetInstance(RealtimeConnection::class);
    $this->order = new SignedExecutorOrder('cGF5bG9hZA==.c2lnbmF0dXJl', '42', str_repeat('ab', 16), 1_800_000_005_000);
    $this->operation = (new Operation)->forceFill(['id' => 42, 'node_id' => 7]);
});

it("publishes executor-order on the channel of the Operation's node_id with its id and the wire form unchanged", function (): void {
    $this->mock(WebSocketCredentialManager::class)->shouldReceive('current')->andReturn(new WebSocketCredentials('test-app', 'test-key', 'test-secret'));

    app(ExecutorOrderPublisher::class)->publish($this->operation, $this->order);

    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Event::assertDispatched(ExecutorOrderBroadcast::class, function (ExecutorOrderBroadcast $event): bool {
        $channels = array_map(fn (Channel $channel): string => $channel->name, $event->broadcastOn());

        return $channels === ['presence-node.7']
            && $event->broadcastAs() === 'executor-order'
            && $event->broadcastWith() === ['operation' => '42', 'order' => 'cGF5bG9hZA==.c2lnbmF0dXJl'];
    });
    expect(config('broadcasting.connections.reverb.key'))->toBe('test-key');
});

it('throws when no websocket role is active, so the caller can fall back at once', function (): void {
    $this->mock(WebSocketCredentialManager::class)->shouldReceive('current')->andReturn(null);

    expect(fn () => app(ExecutorOrderPublisher::class)->publish($this->operation, $this->order))->toThrow(RuntimeException::class, 'No websocket role is active.');
    Event::assertNotDispatched(ExecutorOrderBroadcast::class);
});

it('publishes nothing for an Operation without a node_id or for the order of another Operation', function (?int $nodeId, string $orderOperation): void {
    $this->mock(WebSocketCredentialManager::class)->shouldReceive('current')->andReturn(new WebSocketCredentials('test-app', 'test-key', 'test-secret'));
    $order = new SignedExecutorOrder('cGF5bG9hZA==.c2lnbmF0dXJl', $orderOperation, str_repeat('ab', 16), 1_800_000_005_000);

    expect(fn () => app(ExecutorOrderPublisher::class)->publish((new Operation)->forceFill(['id' => 42, 'node_id' => $nodeId]), $order))
        ->toThrow(InvalidArgumentException::class);
    Event::assertNotDispatched(ExecutorOrderBroadcast::class);
})->with([
    'no node_id' => [null, '42'],
    'another Operation' => [7, '43'],
]);
