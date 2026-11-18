<?php

declare(strict_types=1);

namespace App\Spike\Nats;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Basis\Nats\Consumer\AckPolicy;
use Basis\Nats\Message\Payload;
use Basis\Nats\Stream\RetentionPolicy;
use Basis\Nats\Stream\StorageBackend;

/**
 * SPIKE: the agent bus on NATS JetStream. Orders go into ORDERS (`orbit.node.<id>.orders`), one durable consumer per
 * Node, so an offline Node gets its orders when it returns and an unacknowledged order comes back after `ack_wait`.
 * Receipts go into RECEIPTS (`orbit.receipts.<id>`), read by the Gateway's durable consumer and acknowledged only after
 * the transition, so no receipt is lost while the Gateway is down. Both streams are work queues with a one-hour age
 * limit: nothing piles up.
 */
final class Bus
{
    private const int Second = 1_000_000_000;

    private static ?Client $client = null;

    public static function client(): Client
    {
        return self::$client ??= self::connect();
    }

    /** A connection of its own, so a subscription never shares a socket with publishes made inside its handler. */
    public static function connect(): Client
    {
        return new Client(new Configuration(
            host: (string) env('NATS_HOST', '127.0.0.1'), port: 4222,
            user: (string) env('NATS_USER'), pass: (string) env('NATS_PASSWORD'), timeout: 2,
        ));
    }

    /** @param list<int> $nodes */
    public static function setup(array $nodes): void
    {
        $api = self::client()->getApi();
        foreach (['ORDERS' => 'orbit.node.*.orders', 'RECEIPTS' => 'orbit.receipts.*'] as $name => $subject) {
            $stream = $api->getStream($name);
            $stream->getConfiguration()->setSubjects([$subject])->setRetentionPolicy(RetentionPolicy::WORK_QUEUE)
                ->setStorageBackend(StorageBackend::FILE)->setMaxAge(3600 * self::Second)->setDuplicateWindow(120)
                ->setMaxBytes(256 * 1024 * 1024);
            $stream->createIfNotExists();
        }
        foreach ($nodes as $node) {
            $consumer = $api->getStream('ORDERS')->getConsumer("node-{$node}");
            $consumer->getConfiguration()->setSubjectFilter("orbit.node.{$node}.orders")->setAckPolicy(AckPolicy::EXPLICIT)
                ->setAckWait(5 * self::Second)->setMaxDeliver(60);
            $consumer->create();
        }
        $receipts = $api->getStream('RECEIPTS')->getConsumer('gateway');
        $receipts->getConfiguration()->setSubjectFilter('orbit.receipts.*')->setAckPolicy(AckPolicy::EXPLICIT)
            ->setAckWait(10 * self::Second)->setMaxDeliver(60);
        $receipts->create();
    }

    /**
     * Content for one Node's order: a KV entry `n<node>.<sha256>` in the CONTENT bucket, kept one hour. The order names only the hash;
     * the executor reads the entry over its own NATS connection (its user may read only its own `n<node>.` keys) and checks the hash.
     * Small files only (max_payload, 1 MB); no HTTP side channel.
     *
     * @return array{sha256: string}
     */
    public static function putContent(int $node, string $content): array
    {
        $sha = hash('sha256', $content);
        $ack = self::client()->getApi()->getStream('KV_CONTENT')->publish("\$KV.CONTENT.n{$node}.{$sha}", $content);
        $seq = json_decode(is_object($ack) ? (string) $ack->body : (string) $ack, true)['seq'] ?? null;
        if (! is_int($seq)) {
            throw new \RuntimeException('nats.content_not_stored');
        }

        return ['sha256' => $sha];
    }

    /** The CONTENT bucket: one value per key, one hour, direct gets so a Node reads a key without a consumer. */
    public static function setupContent(): void
    {
        $config = ['name' => 'KV_CONTENT', 'subjects' => ['$KV.CONTENT.>'], 'retention' => 'limits', 'storage' => 'file',
            'max_msgs_per_subject' => 1, 'max_age' => 3600 * self::Second, 'max_msg_size' => 1024 * 1024, 'max_bytes' => 256 * 1024 * 1024,
            'discard' => 'old', 'allow_direct' => true, 'allow_rollup_hdrs' => true, 'num_replicas' => 1];
        try {
            self::client()->api('STREAM.CREATE.KV_CONTENT', $config);
        } catch (\Throwable) {
            self::client()->api('STREAM.UPDATE.KV_CONTENT', $config);
        }
    }

    /** Publishes one signed order and waits for JetStream to store it. The nonce makes a retried publish land once. */
    public static function publishOrder(int $node, int $operation, string $wire, string $nonce): void
    {
        $started = microtime(true);
        try {
            $ack = self::client()->getApi()->getStream('ORDERS')->publish("orbit.node.{$node}.orders",
                new Payload(json_encode(['operation' => (string) $operation, 'order' => $wire], JSON_THROW_ON_ERROR), ['Nats-Msg-Id' => $nonce]));
            // Stored only when JetStream answers with the stream and a sequence; anything else is a lost order.
            $seq = json_decode(is_object($ack) ? (string) $ack->body : (string) $ack, true)['seq'] ?? null;
            if (! is_int($seq)) {
                throw new \RuntimeException('nats.order_not_stored: '.substr(json_encode($ack) ?: '', 0, 200));
            }
            self::trace($operation, $node, 'stored seq '.$seq, $started);
        } catch (\Throwable $exception) {
            self::$client = null; // Reconnect on the next publish.
            self::trace($operation, $node, 'FAILED '.$exception->getMessage(), $started);
            throw $exception;
        }
    }

    private static function trace(int $operation, int $node, string $what, float $started): void
    {
        @file_put_contents(storage_path('logs/spike-publish.log'), sprintf("%s pid %d op %d node %d %.0f ms %s\n",
            date('H:i:s'), getmypid(), $operation, $node, (microtime(true) - $started) * 1000, $what), FILE_APPEND);
    }
}
