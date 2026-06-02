<?php

declare(strict_types=1);

use App\Exceptions\GatewayApiException;
use App\Services\GatewayOperationEventStreamClient;
use App\Services\GatewayOperationFollower;
use Illuminate\Http\Client\ConnectionException;
use Orbit\Core\Progress\ProgressEventType;

it('follows operation events from the first replay through terminal success', function (): void {
    $client = new FakeGatewayOperationEventStreamClient([
        [
            ['id' => 1, 'type' => ProgressEventType::Tree, 'payload' => ['title' => 'Update all']],
            ['id' => 2, 'type' => ProgressEventType::Complete, 'payload' => ['exit_code' => 0]],
        ],
    ]);
    $events = [];

    $terminal = (new GatewayOperationFollower($client, reconnectSleepMs: 0, maxEmptyReplays: 1))
        ->follow('/api/operations/run-1/events', function (ProgressEventType $type, array $payload) use (&$events): void {
            $events[] = [$type->value, $payload];
        });

    expect($terminal['type'])->toBe(ProgressEventType::Complete)
        ->and($terminal['payload'])->toBe(['exit_code' => 0])
        ->and($events)->toBe([
            ['tree', ['title' => 'Update all']],
            ['complete', ['exit_code' => 0]],
        ])
        ->and($client->lastEventIds)->toBe([null]);
});

it('reconnects with last event id after a non terminal replay closes', function (): void {
    $client = new FakeGatewayOperationEventStreamClient([
        [
            ['id' => 5, 'type' => ProgressEventType::Step, 'payload' => ['message' => 'runner started']],
        ],
        [
            ['id' => 6, 'type' => ProgressEventType::Complete, 'payload' => ['exit_code' => 0]],
        ],
    ]);

    $terminal = (new GatewayOperationFollower($client, reconnectSleepMs: 0, maxEmptyReplays: 1))
        ->follow('/api/operations/run-1/events', fn () => null);

    expect($terminal['type'])->toBe(ProgressEventType::Complete)
        ->and($client->lastEventIds)->toBe([null, 5]);
});

it('suppresses duplicate event ids after reconnect replay', function (): void {
    $client = new FakeGatewayOperationEventStreamClient([
        [
            ['id' => 7, 'type' => ProgressEventType::Step, 'payload' => ['message' => 'runner started']],
        ],
        [
            ['id' => 7, 'type' => ProgressEventType::Step, 'payload' => ['message' => 'runner started']],
            ['id' => 8, 'type' => ProgressEventType::Complete, 'payload' => ['exit_code' => 0]],
        ],
    ]);
    $events = [];

    (new GatewayOperationFollower($client, reconnectSleepMs: 0, maxEmptyReplays: 1))
        ->follow('/api/operations/run-1/events', function (ProgressEventType $type, array $payload) use (&$events): void {
            $events[] = [$type->value, $payload];
        });

    expect($events)->toBe([
        ['step', ['message' => 'runner started']],
        ['complete', ['exit_code' => 0]],
    ]);
});

it('returns terminal failure events', function (): void {
    $client = new FakeGatewayOperationEventStreamClient([
        [
            ['id' => 9, 'type' => ProgressEventType::Error, 'payload' => ['message' => 'gateway failed']],
        ],
    ]);

    $terminal = (new GatewayOperationFollower($client, reconnectSleepMs: 0, maxEmptyReplays: 1))
        ->follow('/api/operations/run-1/events', fn () => null);

    expect($terminal['type'])->toBe(ProgressEventType::Error)
        ->and($terminal['payload'])->toBe(['message' => 'gateway failed']);
});

it('retries transient gateway stream failures during replacement', function (): void {
    $client = new FakeGatewayOperationEventStreamClient([
        GatewayApiException::networkError(new ConnectionException('connection reset')),
        [
            ['id' => 10, 'type' => ProgressEventType::Complete, 'payload' => ['exit_code' => 0]],
        ],
    ]);

    $terminal = (new GatewayOperationFollower($client, reconnectSleepMs: 0, maxEmptyReplays: 1, maxTransientFailures: 1))
        ->follow('/api/operations/run-1/events', fn () => null);

    expect($terminal['type'])->toBe(ProgressEventType::Complete)
        ->and($client->lastEventIds)->toBe([null, null]);
});

it('fails after the configured number of empty replays', function (): void {
    $client = new FakeGatewayOperationEventStreamClient([
        [],
        [],
    ]);

    expect(fn () => (new GatewayOperationFollower($client, reconnectSleepMs: 0, maxEmptyReplays: 2))
        ->follow('/api/operations/run-1/events', fn () => null))
        ->toThrow(GatewayApiException::class, 'terminal frame');
});

class FakeGatewayOperationEventStreamClient extends GatewayOperationEventStreamClient
{
    /**
     * @var list<int|null>
     */
    public array $lastEventIds = [];

    /**
     * @param  list<list<array{id: int|null, type: ProgressEventType, payload: array<string, mixed>}>|GatewayApiException>  $replays
     */
    public function __construct(
        private array $replays,
    ) {}

    /**
     * @param  callable(ProgressEventType, array<string, mixed>, int|null): void  $onEvent
     */
    #[Override]
    public function replay(string $eventsUrl, ?int $lastEventId, callable $onEvent): ?array
    {
        $this->lastEventIds[] = $lastEventId;
        $replay = array_shift($this->replays) ?? [];

        if ($replay instanceof GatewayApiException) {
            throw $replay;
        }

        foreach ($replay as $event) {
            $onEvent($event['type'], $event['payload'], $event['id']);

            if ($event['type'] === ProgressEventType::Complete || $event['type'] === ProgressEventType::Error) {
                return [
                    'type' => $event['type'],
                    'payload' => $event['payload'],
                ];
            }
        }

        return null;
    }
}
