<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

use JsonException;

/**
 * One reply line of the executor, as the observer sends it back in the client event `client-executor-result`
 * (ADR 0266, docs/reference/executor.md#the-listener-and-the-hand-off). The event's data is exactly
 * `{"operation": "<numeric string>", "line": "<reply line>"}`: the observer copies `operation` from the
 * `executor-order` event `{"operation": ..., "order": ...}`, because the `accepted` line and an order-check refusal
 * do not name the Operation.
 */
final readonly class ExecutorReply
{
    /** The longest reply the observer reads (ADR 0266). */
    public const int MaxLineBytes = 65_536;

    public const string Accepted = 'accepted';

    /** @var list<string> The statuses of a result line. `partial` arrives with the wake (slice 5). */
    public const array Results = ['done', 'partial', 'failed', 'refused'];

    /** @param array<string, mixed> $line */
    private function __construct(
        public int $operation,
        public string $status,
        public ?string $reason,
        public array $line,
        public string $raw,
    ) {}

    /**
     * Null when the data is not one well-formed reply for one Operation id.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromEvent(array $data): ?self
    {
        $operation = $data['operation'] ?? null;
        $raw = $data['line'] ?? null;
        $keys = array_keys($data);
        sort($keys);

        if ($keys !== ['line', 'operation'] || ! is_string($operation) || preg_match('/\A[1-9][0-9]{0,17}\z/D', $operation) !== 1
            || ! is_string($raw) || $raw === '' || strlen($raw) > self::MaxLineBytes) {
            return null;
        }

        try {
            $line = json_decode(trim($raw), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($line) || array_is_list($line)) {
            return null;
        }

        $status = $line['status'] ?? null;

        // A line that names its Operation must name the one the observer sent the order for.
        if (! in_array($status, [self::Accepted, ...self::Results], strict: true)
            || (array_key_exists('operation', $line) && $line['operation'] !== null && $line['operation'] !== $operation)) {
            return null;
        }

        $reason = $status === 'refused' ? ($line['reason'] ?? null) : ($status === 'failed' ? ($line['code'] ?? null) : null);

        /** @var array<string, mixed> $line */
        return new self((int) $operation, $status, is_string($reason) ? mb_strcut($reason, 0, 255, 'UTF-8') : null, $line, trim($raw));
    }

    public function isAccepted(): bool
    {
        return $this->status === self::Accepted;
    }

    public function isReplay(): bool
    {
        return $this->status === 'refused' && $this->reason === 'replayed_nonce';
    }

    public function first(): ?self
    {
        $first = $this->line['first'] ?? null;
        if (! $this->isReplay() || ! is_array($first) || ! in_array($first['status'] ?? null, ['done', 'partial', 'failed'], true)) {
            return null;
        }

        return self::fromEvent(['operation' => (string) $this->operation, 'line' => json_encode($first, JSON_THROW_ON_ERROR)]);
    }
}
