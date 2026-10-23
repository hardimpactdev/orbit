<?php

declare(strict_types=1);

use App\Support\Operations\OperationFollower;
use App\Support\Operations\OperationOutputLine;
use App\Support\Operations\OperationOutputTail;
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

it('hands each live output line of the channel to onOutput and stays connected', function (): void {
    Sleep::fake();
    $transport = new FakeWebSocketTransport([
        ['event' => 'pusher:connection_established', 'data' => json_encode(['socket_id' => 's1'])],
        ['event' => 'pusher_internal:subscription_succeeded', 'channel' => 'private-orbit', 'data' => '{}'],
        ['event' => 'client-output', 'channel' => 'private-orbit', 'data' => json_encode(['step' => 'migrate', 'stream' => 'stdout', 'sequence' => 1, 'line' => 'Migrating'])],
        ['event' => 'client-output', 'channel' => 'private-orbit', 'data' => json_encode(['line' => 'no step'])],
    ]);
    $subscriber = new RealtimeSubscriber($transport, realtime_test_connection_config(), new FakeRealtimeChannelAuthorizer);
    $lines = [];
    $follower = new OperationFollower(
        static fn (int $after): OperationResponse => follower_operation(4, 'succeeded'),
        $subscriber,
        static function (OperationOutputLine $output) use (&$lines): void {
            $lines[] = "{$output->step}: {$output->line}";
        },
    );

    $follower->next(follower_operation(3, 'running'));

    expect($lines)->toBe(['migrate: Migrating'])
        ->and($subscriber->state())->toBe(RealtimeState::Connected);
});

it('prints no record tail for a step whose output printed live', function (): void {
    $tail = new OperationOutputTail;

    expect($tail->lines('build', "one\n"))->toBe(['one']);

    $tail->printedLive('migrate');

    expect($tail->lines('migrate', "Migrating\n"))->toBe([]);
});
