<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use Closure;
use Illuminate\Support\Str;

/**
 * Serve's handshake limit as its source sets it (src/serve/rate_limiter.ts at 537e03d1; the address burst is the same as at db3f6373): 50 WebSocket upgrades from one address in a
 * fixed 60 s window, all tokens together, a refused one answered 429 with the rest of the window as Retry-After. Every Gateway
 * connect reaches serve through its local TLS proxy as the one address 10.44.0.2. It answers vault.put, vault.read-secret,
 * workflow.run and data.get like serve, and counts every upgrade by token. `$answer` may answer any other request.
 */
final class HandshakeCountingServe implements WebSocketClient
{
    public const int Limit = 50;

    public const int WindowSeconds = 60;

    /** @var array<string, int> */
    public array $connects = [];

    public int $refused = 0;

    /** The seconds that reads with no answer waited. A real socket blocks for its timeout; `clock()` counts it without waiting. */
    public float $waited = 0.0;

    /** @var list<array<string,mixed>> */
    public array $frames = [];

    /** @var array<string, string> data.get content by data name */
    public array $data = [];

    /** @var (Closure(array<string,mixed>): (array<string,mixed>|false|null))|null false: serve never answers this request; null: as serve does */
    public ?Closure $answer = null;

    /** @var list<array<string,mixed>> */
    private array $unanswered = [];

    private int $inWindow = 0;

    private ?int $windowEnds = null;

    private bool $open = false;

    public function __construct(private int $limit = self::Limit, private int $windowSeconds = self::WindowSeconds) {}

    public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
    {
        $now = now()->getTimestamp();
        if ($this->windowEnds === null || $now > $this->windowEnds) {
            [$this->windowEnds, $this->inWindow] = [$now + $this->windowSeconds, 0];
        }
        $this->connects[(string) $endpoint->bearerToken] = ($this->connects[(string) $endpoint->bearerToken] ?? 0) + 1;
        if (++$this->inWindow > $this->limit) {
            $this->refused++;
            throw new WebSocketException('refused', 429, retryAfterSeconds: max(1, $this->windowEnds - $now));
        }
        $this->open = true;
        $this->unanswered = [];
    }

    public function send(array $message): void
    {
        if (! $this->open) {
            throw new WebSocketException('not connected');
        }
        $this->frames[] = $message;
        $this->unanswered[] = $message;
    }

    public function receive(float $timeoutSeconds): array
    {
        $answers = [];
        foreach ($this->unanswered as $frame) {
            $answer = $this->answerFor($frame);
            if (is_array($answer)) {
                $answers[] = $answer;
            }
        }
        $this->unanswered = [];
        if ($answers === []) {
            $this->waited += $timeoutSeconds;
        }

        return $answers;
    }

    /** The clock of a reader of this serve: only the time its unanswered reads would have blocked, so no real pause counts. */
    public function clock(): float
    {
        return $this->waited;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function isConnected(): bool
    {
        return $this->open;
    }

    public function total(): int
    {
        return array_sum($this->connects);
    }

    /**
     * @param  array<string,mixed>  $frame
     * @return array<string,mixed>|false|null
     */
    private function answerFor(array $frame): array|false|null
    {
        $custom = $this->answer === null ? null : ($this->answer)($frame);
        if ($custom !== null) {
            return $custom;
        }
        $payload = is_array($frame['payload'] ?? null) ? $frame['payload'] : [];
        $data = ['vaultName' => $payload['vaultName'] ?? 'orbit-control-inputs',
            'secretKey' => $payload['secretKey'] ?? $payload['key'] ?? '', 'vaultType' => 'local_encryption'];

        return match ($frame['type']) {
            'vault.put' => ['type' => 'vault.put', 'id' => $frame['id'], 'payload' => ['data' => $data]],
            'vault.read-secret' => ['type' => 'vault.read-secret', 'id' => $frame['id'], 'payload' => ['data' => [...$data, 'value' => 'gateway-session']]],
            'workflow.run' => ['type' => 'event', 'id' => $frame['id'], 'event' => ['kind' => 'started',
                'workflowName' => $payload['workflowIdOrName'], 'runId' => (string) Str::uuid()]],
            'data.get' => $this->dataGet($frame['id'], (string) ($payload['dataName'] ?? '')),
            default => null,
        };
    }

    /** @return array<string,mixed> */
    private function dataGet(string $id, string $name): array
    {
        $content = $this->data[$name] ?? null;
        if ($content === null) {
            return ['type' => 'error', 'id' => $id, 'payload' => ['message' => 'not found']];
        }

        return ['type' => 'data.get', 'id' => $id, 'payload' => ['data' => ['modelName' => 'orbit-gateway', 'name' => $name, 'version' => 1,
            'contentType' => 'application/json', 'contentEncoding' => 'utf-8', 'content' => $content, 'size' => strlen($content),
            'checksum' => hash('sha256', $content)]]];
    }
}
