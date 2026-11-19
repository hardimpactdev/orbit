<?php

declare(strict_types=1);

use App\Infrastructure\Engine\EngineBus;
use Basis\Nats\Client;
use Basis\Nats\Message\Payload;

/** A connection that never touches the network and answers pings as told. */
final class IdleTestClient extends Client
{
    public bool $alive = true;

    public int $pings = 0;

    public function ping(): bool
    {
        $this->pings++;

        return $this->alive;
    }
}

it('answers a message its work handled with an acknowledgement', function (): void {
    $handler = EngineBus::settle(static function (Payload $payload): void {});

    expect($handler(new Payload('{}')))->toBe(EngineBus::Ack);
});

it('answers a message whose work threw with a delayed negative acknowledgement, the only reply it sends', function (): void {
    $handler = EngineBus::settle(static fn (Payload $payload) => throw new RuntimeException('engine.publish_failed'));

    expect($handler(new Payload('{}')))->toBe('-NAK {"delay": 1000000000}');
});

it('replaces a connection that sat idle and no longer answers a ping', function (): void {
    $made = [];
    $bus = new EngineBus(function () use (&$made): Client {
        return $made[] = new IdleTestClient;
    });

    $first = $bus->client();
    $this->travel(5)->seconds();
    expect($bus->client())->toBe($first)->and($first->pings)->toBe(0);

    $this->travel(11)->seconds();
    expect($bus->client())->toBe($first)->and($first->pings)->toBe(1);

    $first->alive = false;
    $this->travel(11)->seconds();
    expect($bus->client())->not->toBe($first)->and($made)->toHaveCount(2);
});

it('takes a receipt\'s Node from its subject and ignores the Node its body names', function (): void {
    $payload = new Payload('{"node": 9, "line": {"operation": "5:ensure-0", "status": "applied"}}');
    $payload->subject = 'orbit.receipts.3';

    expect(EngineBus::receipt($payload))->toBe(['node' => 3, 'line' => ['operation' => '5:ensure-0', 'status' => 'applied']]);
});

it('terminates a message it cannot read, so it never comes back', function (): void {
    $unreadable = new Payload('not json');
    $unreadable->subject = 'orbit.receipts.3';
    $elsewhere = new Payload('{"line": {}}');
    $elsewhere->subject = 'orbit.receipts.x';
    $handler = EngineBus::settle(static fn (Payload $payload) => EngineBus::receipt($payload));

    expect($handler($unreadable))->toBe(EngineBus::Term)
        ->and($handler($elsewhere))->toBe(EngineBus::Term);
});
