<?php

declare(strict_types=1);

namespace App\Spike\Nats;

use App\Spike\StateMachine\Engine;
use Basis\Nats\Message\Payload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * SPIKE: `spike:nats setup` makes the streams and consumers; `spike:nats receipts` is the Gateway's one long-running
 * receipt consumer (it replaces the listener and the Valkey lists for executor results); `spike:nats transport nats|reverb`
 * picks how the engine sends orders.
 */
final class SpikeNatsCommand extends Command
{
    protected $signature = 'spike:nats {mode : setup|receipts|push|transport} {value?}';

    protected $description = 'SPIKE: the agent bus on NATS JetStream';

    public function handle(): int
    {
        return match ($this->argument('mode')) {
            'setup' => $this->setup(),
            'receipts' => $this->receipts(),
            'push' => $this->push(),
            'transport' => $this->transport((string) $this->argument('value')),
            default => self::INVALID,
        };
    }

    private function setup(): int
    {
        Bus::setup([1, 3, 4]);
        Bus::setupContent();
        $this->line('streams ORDERS, RECEIPTS and consumers node-1, node-3, node-4, gateway are ready');

        return self::SUCCESS;
    }

    private function transport(string $value): int
    {
        Cache::forever('spike:transport', $value === 'nats' ? 'nats' : 'reverb');
        $this->line('spike:transport = '.Cache::get('spike:transport'));

        return self::SUCCESS;
    }

    /**
     * The push variant: JetStream delivers each receipt to a subscription as soon as it is stored, and the Gateway acks it
     * after the transition (`+ACK` to the message's reply subject). No pull round trip per receipt.
     */
    private function push(): int
    {
        $stream = Bus::client()->getApi()->getStream('RECEIPTS');
        if (in_array('gateway', $stream->getConsumerNames(), true)) {
            $stream->getConsumer('gateway')->delete(); // a work-queue stream allows no overlapping consumers
        }
        $consumer = $stream->getConsumer('gateway-push');
        $consumer->getConfiguration()->setDeliverSubject('spike.receipts.push')->setSubjectFilter('orbit.receipts.*')
            ->setAckPolicy(\Basis\Nats\Consumer\AckPolicy::EXPLICIT)->setAckWait(10_000_000_000)->setMaxDeliver(60);
        $consumer->create();
        $subscriber = Bus::connect();
        $subscriber->subscribe('spike.receipts.push', function (Payload $payload, ?string $reply) use ($subscriber): void {
            $receipt = json_decode($payload->body, true, flags: JSON_THROW_ON_ERROR);
            try {
                Engine::onReceipt((int) $receipt['operation'], (int) $receipt['node'], (array) $receipt['line']);
                $verdict = '+ACK';
            } catch (\Throwable $exception) {
                report($exception);
                $verdict = '-NAK {"delay": 1000000000}'; // Redeliver in 1 s; a receipt applied twice only counts as late.
            }
            if ($reply !== null) {
                $subscriber->publish($reply, $verdict);
            }
        });
        $this->line('push consumer gateway-push is live');
        while (true) {
            $subscriber->process(1);
        }
    }

    /** Applies each receipt to the engine and acknowledges it only afterwards; a crash before the ack redelivers it. */
    private function receipts(): int
    {
        $consumer = Bus::client()->getApi()->getStream('RECEIPTS')->getConsumer('gateway');
        $consumer->setBatching(1)->setExpires(0.5)->setDelay(0)->setIterations(PHP_INT_MAX);
        $consumer->handle(function (Payload $payload): void {
            $receipt = json_decode($payload->body, true, flags: JSON_THROW_ON_ERROR);
            Engine::onReceipt((int) $receipt['operation'], (int) $receipt['node'], (array) $receipt['line']);
        });

        return self::SUCCESS;
    }
}
