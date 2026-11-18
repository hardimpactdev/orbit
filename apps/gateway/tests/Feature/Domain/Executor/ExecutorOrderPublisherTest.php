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
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Testing\Fakes\EventFake;

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

it('caps Pusher and Guzzle by the request budget and restores later broadcast limits', function (): void {
    $this->mock(WebSocketCredentialManager::class)->shouldReceive('current')->andReturn(new WebSocketCredentials('test-app', 'test-key', 'test-secret'));
    $original = config('broadcasting.connections.reverb.options.timeout');
    $fake = new class(app('events'), [ExecutorOrderBroadcast::class]) extends EventFake
    {
        /** @var list<float> */
        public array $limits = [];

        public function dispatch($event, $payload = [], $halt = false)
        {
            if ($event instanceof ExecutorOrderBroadcast) {
                $this->limits = [config('broadcasting.connections.reverb.options.timeout'),
                    config('broadcasting.connections.reverb.client_options.timeout'),
                    config('broadcasting.connections.reverb.client_options.connect_timeout')];
            }

            return parent::dispatch($event, $payload, $halt);
        }
    };
    Event::swap($fake);

    app(ExecutorOrderPublisher::class)->publish($this->operation, $this->order, 0.2);

    expect($fake->limits)->toHaveCount(3);
    foreach ($fake->limits as $limit) {
        expect($limit)->toBeGreaterThan(0)->toBeLessThanOrEqual(0.2);
    }
    expect(config('broadcasting.connections.reverb.options.timeout'))->toBe($original);
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
});

it('sends nothing once the publish budget has ended', function (): void {
    $this->mock(WebSocketCredentialManager::class)->shouldReceive('current')->andReturn(new WebSocketCredentials('test-app', 'test-key', 'test-secret'));

    expect(fn () => app(ExecutorOrderPublisher::class)->publish($this->operation, $this->order, 0))->toThrow(RuntimeException::class, 'The executor publish budget ended.');

    Event::assertNotDispatched(ExecutorOrderBroadcast::class);
});

it('only marks the old Reverb down for an attempted failing send', function (bool $attempt): void {
    $home = sys_get_temp_dir().'/orbit-old-executor-reverb-'.bin2hex(random_bytes(8));
    $manager = $this->mock(WebSocketCredentialManager::class);
    $manager->shouldReceive('current')->andReturn(new WebSocketCredentials('fixture-app', 'fixture-key', 'fixture-secret',
        servingAddresses: ['192.0.2.11', '192.0.2.12']));
    $realtime = new RealtimeConnection($manager, $home);
    [, $old] = $realtime->all();
    $broadcaster = $this->mock(Broadcaster::class);
    if ($attempt) {
        $broadcaster->shouldReceive('broadcast')->once()->andThrow(new RuntimeException('Fixture send failed.'));
    } else {
        $broadcaster->shouldNotReceive('broadcast');
    }
    Broadcast::extend('reverb', fn () => $broadcaster);
    $checks = 0;
    $clock = static function () use ($attempt, &$checks): float {
        $checks++;

        return ! $attempt && $checks >= 4 ? 1.0 : 0.0;
    };

    try {
        (new ExecutorOrderPublisher($realtime, $clock))->publish($this->operation, $this->order, 0.5);

        Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
        expect($realtime->oldServerSkipped($old))->toBe($attempt);
    } finally {
        File::deleteDirectory($home);
    }
})->with(['expired before old send' => [false], 'attempted old send fails' => [true]]);
