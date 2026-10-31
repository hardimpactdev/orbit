<?php

declare(strict_types=1);

use App\Support\Console\InterruptIntent;
use App\Support\Operations\OperationFollower;
use App\Support\Realtime\FakeRealtimeChannelAuthorizer;
use App\Support\Realtime\FakeWebSocketTransport;
use App\Support\Realtime\RealtimeState;
use App\Support\Realtime\RealtimeSubscriber;
use Illuminate\Support\Sleep;
use Orbit\Sdk\Responses\Operations\OperationResponse;

function follower_operation(int $sequence, string $status): OperationResponse
{
    return OperationResponse::fromGatewayData([
        'id' => 42, 'kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => 17,
        'status' => $status, 'sequence' => $sequence,
    ], 'req-1');
}

it('reads the current parent record after its channel announces an update', function (): void {
    Sleep::fake();
    $transport = new FakeWebSocketTransport([
        ['event' => 'pusher:connection_established', 'data' => json_encode(['socket_id' => 's1'])],
        ['event' => 'pusher_internal:subscription_succeeded', 'channel' => 'private-orbit', 'data' => '{}'],
        ['event' => 'operation.updated', 'channel' => 'private-orbit', 'data' => json_encode(['type' => 'operation.updated', 'id' => 42, 'at' => '2026-10-31T07:00:00Z', 'data' => ['sequence' => 4]])],
    ]);
    $subscriber = new RealtimeSubscriber($transport, realtime_test_connection_config(), new FakeRealtimeChannelAuthorizer);
    $reads = 0;
    $follower = new OperationFollower(static function () use (&$reads): OperationResponse {
        $reads++;

        return follower_operation(4, 'succeeded');
    }, $subscriber);

    $result = InterruptIntent::run(static fn (): OperationResponse => $follower->next(follower_operation(3, 'running')));

    expect($result->status)->toBe('succeeded');
    expect($reads)->toBe(1);
    expect($subscriber->state())->toBe(RealtimeState::Connected);
    $follower->close();
    expect($transport->isConnected())->toBeFalse();
});

it('reads a terminal parent without a realtime connection or step cursor', function (): void {
    $follower = new OperationFollower(static fn (): OperationResponse => follower_operation(4, 'failed'), null);

    $result = InterruptIntent::run(static fn (): OperationResponse => $follower->next(follower_operation(3, 'running')));

    expect($result->status)->toBe('failed');
    expect($result->toArray())->not->toHaveKey('steps');
});
