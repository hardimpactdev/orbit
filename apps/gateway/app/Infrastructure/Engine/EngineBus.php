<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine;

use App\Domain\Engine\MachineBus;
use App\Domain\Executor\SignedExecutorOrder;
use App\Support\ValidatedData;
use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Basis\Nats\Message\Payload;
use Closure;
use RuntimeException;
use Throwable;

/**
 * The Gateway's side of the machine bus (ADR 0271; docs/reference/gateway-engine.md): NATS JetStream streams for orders,
 * receipts, the state machine's own events and content. Every publish waits for JetStream's acknowledgement and checks
 * its sequence: an unacknowledged publish is a lost instruction, and the caller must know.
 */
final class EngineBus implements MachineBus
{
    private const int Second = 1_000_000_000;

    public const string ReceiptsDeliver = 'orbit.gw.receipts';

    public const string EventsDeliver = 'orbit.gw.engine';

    private ?Client $client = null;

    /** @var array<int, true> */
    private array $nodes = [];

    public function client(): Client
    {
        return $this->client ??= self::connect();
    }

    /** A connection of its own, so a subscription never shares a socket with publishes made inside its handler. */
    public static function connect(): Client
    {
        $nats = config('orbit.engine.nats');
        $host = is_array($nats) ? ($nats['host'] ?? null) : null;
        $port = is_array($nats) ? ($nats['port'] ?? null) : null;
        $user = is_array($nats) ? ($nats['user'] ?? null) : null;
        $password = is_array($nats) ? ($nats['password'] ?? null) : null;
        if (! is_string($host) || ! is_int($port) || ! is_string($user) || ! is_string($password) || $password === '') {
            throw new RuntimeException('engine.bus_unconfigured');
        }

        return new Client(new Configuration(host: $host, port: $port, user: $user, pass: $password, timeout: 2));
    }

    /** The streams; idempotent. Work queues keep nothing once acknowledged, and every stream ages out. */
    public function setup(): void
    {
        $limits = ['storage' => 'file', 'num_replicas' => 1, 'duplicate_window' => 120 * self::Second, 'max_bytes' => 256 * 1024 * 1024];
        $this->stream('ORDERS', [...$limits, 'subjects' => ['orbit.node.*.orders'], 'retention' => 'workqueue', 'max_age' => 3600 * self::Second]);
        $this->stream('RECEIPTS', [...$limits, 'subjects' => ['orbit.receipts.*'], 'retention' => 'workqueue', 'max_age' => 3600 * self::Second]);
        $this->stream('ENGINE', [...$limits, 'subjects' => ['orbit.engine.>'], 'retention' => 'limits', 'max_age' => 86_400 * self::Second,
            'allow_msg_schedules' => true]);
        $this->stream('KV_CONTENT', [...$limits, 'subjects' => ['$KV.CONTENT.>'], 'retention' => 'limits', 'max_age' => 3600 * self::Second,
            'max_msgs_per_subject' => 1, 'max_msg_size' => 1024 * 1024, 'discard' => 'old', 'allow_direct' => true, 'allow_rollup_hdrs' => true]);
        // What each Node's executor reports when it joins: its primitives. Kept until the Node reports again.
        $this->stream('KV_NODES', [...$limits, 'subjects' => ['$KV.NODES.>'], 'retention' => 'limits', 'max_msgs_per_subject' => 1,
            'discard' => 'old', 'allow_direct' => true, 'allow_rollup_hdrs' => true]);
    }

    /** A Node's durable order consumer, made once per process. */
    public function ensureNode(int $node): void
    {
        if (isset($this->nodes[$node])) {
            return;
        }
        $this->consumer('ORDERS', "node-{$node}", ['filter_subject' => "orbit.node.{$node}.orders", 'ack_policy' => 'explicit',
            'ack_wait' => 5 * self::Second, 'max_deliver' => -1, 'deliver_policy' => 'all']);
        $this->nodes[$node] = true;
    }

    /** Publishes one signed order to its Node. The nonce makes a repeated publish land once. */
    public function publishOrder(int $node, SignedExecutorOrder $order): void
    {
        $this->ensureNode($node);
        $this->publish('ORDERS', "orbit.node.{$node}.orders", $order->wire, ['Nats-Msg-Id' => $order->nonce]);
    }

    /** The start event of an engine Operation: the consumer handles it like a receipt. */
    public function publishStart(int $operation): void
    {
        $this->publish('ENGINE', 'orbit.engine.start', json_encode(['operation' => $operation], JSON_THROW_ON_ERROR),
            ['Nats-Msg-Id' => "start-{$operation}"]);
    }

    /** A deadline that JetStream delivers to the engine at `$atMs`; no Gateway process polls for it. */
    public function scheduleDeadline(int $operation, string $step, int $sends, int $atMs): void
    {
        $at = gmdate('Y-m-d\TH:i:s\Z', intdiv($atMs + 999, 1000));
        $this->publish('ENGINE', "orbit.engine.schedule.{$operation}.{$step}.{$sends}", json_encode(['operation' => $operation, 'step' => $step],
            JSON_THROW_ON_ERROR), ['Nats-Schedule' => '@at '.$at, 'Nats-Schedule-Target' => 'orbit.engine.deadline']);
    }

    /**
     * Content for one Node's order, kept one hour under `n<node>.<sha256>`. The order names only the hash; the Node's NATS user
     * may read only its own keys.
     *
     * @return array{sha256: string}
     */
    public function putContent(int $node, string $content): array
    {
        $digest = hash('sha256', $content);
        $this->publish('KV_CONTENT', "\$KV.CONTENT.n{$node}.{$digest}", $content, []);

        return ['sha256' => $digest];
    }

    /**
     * The primitives a Node's executor reported when it joined the bus (KV `NODES`, key `n<node>`); empty when it never did.
     *
     * @return list<string>
     */
    public function nodeActions(int $node): array
    {
        try {
            $reply = $this->client()->dispatch("\$JS.API.DIRECT.GET.KV_NODES.\$KV.NODES.n{$node}", '');
        } catch (Throwable) {
            return [];
        }
        $body = is_object($reply) && isset($reply->body) ? (string) $reply->body : (is_string($reply) ? $reply : '');
        $decoded = json_decode($body, true);

        return is_array($decoded) && is_array($decoded['actions'] ?? null) ? array_values(array_filter($decoded['actions'], is_string(...))) : [];
    }

    /**
     * Delivers receipts and engine events to the handlers until the process stops. A handler that throws gets its message
     * redelivered after a second; a handler that returns acknowledges it.
     *
     * @param  Closure(array<string, mixed>): void  $receipt
     * @param  Closure(string, array<string, mixed>): void  $event
     */
    public function consume(Closure $receipt, Closure $event): never
    {
        $this->consumer('RECEIPTS', 'gateway-receipts', ['filter_subject' => 'orbit.receipts.*', 'deliver_subject' => self::ReceiptsDeliver,
            'ack_policy' => 'explicit', 'ack_wait' => 30 * self::Second, 'max_deliver' => -1]);
        $this->consumer('ENGINE', 'gateway-engine', ['filter_subjects' => ['orbit.engine.start', 'orbit.engine.deadline'],
            'deliver_subject' => self::EventsDeliver, 'ack_policy' => 'explicit', 'ack_wait' => 30 * self::Second, 'max_deliver' => -1,
            'deliver_policy' => 'new']);
        $subscriber = self::connect();
        $handle = static function (Closure $work) use ($subscriber): Closure {
            return static function (Payload $payload, ?string $reply) use ($work, $subscriber): void {
                try {
                    $work($payload);
                    $verdict = '+ACK';
                } catch (Throwable $exception) {
                    report($exception);
                    $verdict = '-NAK {"delay": 1000000000}';
                }
                if ($reply !== null) {
                    $subscriber->publish($reply, $verdict);
                }
            };
        };
        $subscriber->subscribe(self::ReceiptsDeliver, $handle(static fn (Payload $payload) => $receipt(self::decode($payload))));
        $subscriber->subscribe(self::EventsDeliver, $handle(static fn (Payload $payload) => $event((string) $payload->subject, self::decode($payload))));
        while (true) {
            $subscriber->process(1);
        }
    }

    /** @return array<string, mixed> */
    private static function decode(Payload $payload): array
    {
        return ValidatedData::object(json_decode($payload->body, true, flags: JSON_THROW_ON_ERROR));
    }

    /** @param array<string, string> $headers */
    private function publish(string $stream, string $subject, string $body, array $headers): void
    {
        try {
            $ack = $this->client()->getApi()->getStream($stream)->publish($subject, new Payload($body, $headers));
        } catch (Throwable $exception) {
            $this->client = null; // Reconnect on the next publish.
            throw new RuntimeException('engine.publish_failed', previous: $exception);
        }
        $stored = json_decode(is_object($ack) && isset($ack->body) ? (string) $ack->body : '', true);
        if (! is_array($stored) || ! is_int($stored['seq'] ?? null)) {
            throw new RuntimeException('engine.publish_unacknowledged');
        }
    }

    /** @param array<string, mixed> $config */
    private function stream(string $name, array $config): void
    {
        $config = ['name' => $name, ...$config];
        try {
            $this->client()->api("STREAM.CREATE.{$name}", $config);
        } catch (Throwable) {
            $this->client()->api("STREAM.UPDATE.{$name}", $config);
        }
    }

    /** @param array<string, mixed> $config */
    private function consumer(string $stream, string $name, array $config): void
    {
        $this->client()->api("CONSUMER.CREATE.{$stream}.{$name}", ['stream_name' => $stream, 'config' => ['durable_name' => $name, ...$config]]);
    }
}
