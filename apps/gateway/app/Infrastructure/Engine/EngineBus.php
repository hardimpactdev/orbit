<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine;

use App\Domain\Engine\MachineBus;
use App\Domain\Executor\SignedExecutorOrder;
use App\Support\ValidatedData;
use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Basis\Nats\Message\Payload;
use Carbon\Carbon;
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

    public const string AuthRequests = '$SYS.REQ.USER.AUTH';

    public const string Ack = '+ACK';

    public const string Nak = '-NAK {"delay": 1000000000}';

    public const string Term = '+TERM';

    /** Seconds a connection may sit unused before its next use checks it first. */
    private const int IdleCheckAfter = 10;

    private ?Client $client = null;

    private float $usedAt = 0.0;

    /** @var array<int, true> */
    private array $nodes = [];

    /** @param  (Closure(): Client)|null  $connector  Makes a connection; the default reads the Gateway's configuration. */
    public function __construct(private readonly ?Closure $connector = null) {}

    /**
     * The connection for requests and publishes. The PHP client answers the server's pings only while it reads, so a
     * connection that sat unused in a long-running process (the engine consumer, a queue worker) may be closed by the
     * server. After an idle spell the connection is pinged first, and replaced when the ping fails.
     */
    public function client(): Client
    {
        $now = Carbon::now()->getPreciseTimestamp(3) / 1000;
        if ($this->client !== null && $now - $this->usedAt > self::IdleCheckAfter) {
            try {
                $alive = $this->client->ping();
            } catch (Throwable) {
                $alive = false;
            }
            if (! $alive) {
                $this->client = null;
            }
        }
        $this->usedAt = $now;

        return $this->client ??= ($this->connector ?? static fn (): Client => self::connect(reconnect: false))();
    }

    /** A connection of its own, so a subscription never shares a socket with publishes made inside its handler. */
    public static function connect(bool $reconnect = true): Client
    {
        $host = GatewayBus::address();
        if ($host === null) {
            throw new RuntimeException('engine.bus_unconfigured');
        }

        // The Gateway's own nkey user skips the auth callout and may use every subject of the account.
        // Request/publish connections pass `reconnect: false` so a server-closed idle socket fails `ping()` immediately
        // instead of silently reconnecting inside `getMessage()` and blocking for the full 2 s timeout waiting on an unsent PONG.
        return new Client(new Configuration(host: $host, port: GatewayBus::port(), nkey: GatewayBus::gatewaySeed(), reconnect: $reconnect, timeout: 2));
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
        // What each Node's executor reports when it connects, also before it has a consumer: its primitives.
        // A report lasts five minutes: executors refresh it every minute, so a stopped executor stops receiving kinds. No rollup:
        // a Node's rollup header would purge every Node's report.
        $this->stream('KV_NODES', [...$limits, 'subjects' => ['$KV.NODES.>'], 'retention' => 'limits', 'max_msgs_per_subject' => 1,
            'max_age' => 300 * self::Second, 'discard' => 'old', 'allow_direct' => true, 'allow_rollup_hdrs' => false]);
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
    public function publishOrder(int $node, SignedExecutorOrder $order, ?Closure $claim = null): void
    {
        $this->ensureNode($node);
        $this->publish('ORDERS', "orbit.node.{$node}.orders", $order->wire, ['Nats-Msg-Id' => $order->nonce], $claim);
    }

    /** The start event of an engine Operation: the consumer handles it like a receipt. */
    public function publishStart(int $operation): void
    {
        $this->publish('ENGINE', 'orbit.engine.start', json_encode(['operation' => $operation], JSON_THROW_ON_ERROR),
            ['Nats-Msg-Id' => "start-{$operation}"]);
    }

    public function nudge(int $operation, string $id): void
    {
        $this->publish('ENGINE', 'orbit.engine.deadline', json_encode(['operation' => $operation], JSON_THROW_ON_ERROR),
            ['Nats-Msg-Id' => "{$id}-{$operation}"]);
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
     * The primitives a Node's executor last reported (KV `NODES`, key `n<node>`); empty when no report is under five minutes old.
     *
     * @return list<string>
     */
    public function nodeActions(int $node): array
    {
        $report = $this->nodeReport($node);

        return is_array($report['actions'] ?? null) ? array_values(array_filter($report['actions'], is_string(...))) : [];
    }

    public function nodeTargetActions(int $node): array
    {
        $report = $this->nodeReport($node);

        return is_array($report['node_targets'] ?? null) ? array_values(array_filter($report['node_targets'], is_string(...))) : [];
    }

    public function nodeFeatures(int $node): array
    {
        $report = $this->nodeReport($node);

        return is_array($report['features'] ?? null) ? array_values(array_filter($report['features'], is_string(...))) : [];
    }

    public function nodeClock(int $node): ?bool
    {
        $clock = $this->nodeReport($node)['clock_synchronized'] ?? null;

        return is_bool($clock) ? $clock : null;
    }

    /**
     * A Node executor's last report (KV `NODES`, key `n<node>`); empty when none is under five minutes old.
     *
     * @return array<string, mixed>
     */
    private function nodeReport(int $node): array
    {
        try {
            $reply = $this->client()->dispatch("\$JS.API.DIRECT.GET.KV_NODES.\$KV.NODES.n{$node}", '');
        } catch (Throwable) {
            $this->client = null; // Reconnect on the next call.

            return [];
        }
        $body = is_object($reply) && isset($reply->body) ? (string) $reply->body : (is_string($reply) ? $reply : '');
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Delivers receipts and engine events to the handlers until the process stops. A handler that throws gets its message
     * redelivered after a second; a handler that returns acknowledges it. It also answers the server's auth callout requests
     * with `$authorize`; one that throws refuses the connection, and the client tries again.
     *
     * @param  Closure(array<string, mixed>): void  $receipt
     * @param  Closure(string, array<string, mixed>): void  $event
     * @param  Closure(string): string  $authorize
     */
    public function consume(Closure $receipt, Closure $event, Closure $authorize): never
    {
        $this->consumer('RECEIPTS', 'gateway-receipts', ['filter_subject' => 'orbit.receipts.*', 'deliver_subject' => self::ReceiptsDeliver,
            'ack_policy' => 'explicit', 'ack_wait' => 30 * self::Second, 'max_deliver' => -1]);
        $this->consumer('ENGINE', 'gateway-engine', ['filter_subjects' => ['orbit.engine.start', 'orbit.engine.deadline'],
            'deliver_subject' => self::EventsDeliver, 'ack_policy' => 'explicit', 'ack_wait' => 30 * self::Second, 'max_deliver' => -1,
            'deliver_policy' => 'new']);
        $subscriber = self::connect();
        $subscriber->subscribe(self::ReceiptsDeliver, self::settle(static fn (Payload $payload) => $receipt(self::receipt($payload))));
        $subscriber->subscribe(self::EventsDeliver, self::settle(static fn (Payload $payload) => $event((string) $payload->subject, self::decode($payload))));
        // The auth callout: `nats-server` asks here about every Node connection, and the reply is the answer.
        $subscriber->subscribe(self::AuthRequests, static function (Payload $payload) use ($authorize): string {
            try {
                return $authorize((string) $payload->body);
            } catch (Throwable $exception) {
                report($exception);

                return '';
            }
        });
        while (true) {
            $subscriber->process(1);
        }
    }

    /**
     * A message handler that returns its verdict: the client sends the return value as the one reply on the message's
     * acknowledgement subject. Work that throws is negatively acknowledged and comes back after a second; a message that
     * cannot be read is terminated, so it never comes back.
     *
     * @param  Closure(Payload): void  $work
     * @return Closure(Payload): string
     */
    public static function settle(Closure $work): Closure
    {
        return static function (Payload $payload) use ($work): string {
            try {
                $work($payload);
            } catch (MalformedMessage $exception) {
                report($exception);

                return self::Term; // It would fail the same way every time.
            } catch (Throwable $exception) {
                report($exception);

                return self::Nak;
            }

            return self::Ack;
        };
    }

    /**
     * A receipt as the engine reads it. The Node is the one in the subject, `orbit.receipts.<node>`, which only that Node's NATS
     * user may publish on; a `node` in the body is ignored, so no Node can answer for another.
     *
     * @return array{node: int, line: array<string, mixed>}
     */
    public static function receipt(Payload $payload): array
    {
        if (preg_match('/\Aorbit\.receipts\.([1-9][0-9]{0,9})\z/D', (string) $payload->subject, $match) !== 1) {
            throw new MalformedMessage('engine.receipt_subject_invalid');
        }
        $line = self::decode($payload)['line'] ?? null;
        if (! is_array($line)) {
            throw new MalformedMessage('engine.receipt_invalid');
        }

        return ['node' => (int) $match[1], 'line' => ValidatedData::object($line)];
    }

    /** @return array<string, mixed> */
    private static function decode(Payload $payload): array
    {
        $value = json_decode($payload->body, true);
        if (! is_array($value)) {
            throw new MalformedMessage('engine.message_invalid');
        }

        return ValidatedData::object($value);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  (Closure(): bool)|null  $claim
     */
    private function publish(string $stream, string $subject, string $body, array $headers, ?Closure $claim = null): void
    {
        try {
            $client = $this->client();
            $destination = $client->getApi()->getStream($stream);
            $payload = new Payload($body, $headers);
            if ($claim !== null) {
                $client->subscribeRequests();
                if (! $claim()) {
                    return;
                }
            }
            $ack = $destination->publish($subject, $payload);
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
        try {
            $this->client()->api("CONSUMER.CREATE.{$stream}.{$name}", ['stream_name' => $stream, 'config' => ['durable_name' => $name, ...$config]]);
        } catch (Throwable $exception) {
            $this->client = null; // Reconnect on the next call.
            throw new RuntimeException('engine.consumer_failed', previous: $exception);
        }
    }
}
