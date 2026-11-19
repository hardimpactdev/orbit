<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

use App\Domain\Executor\ExecutorOrderSigner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Operation;
use JsonException;
use RuntimeException;

/**
 * Reads what a Node's executor did with one signed power order, from its journal, when the Gateway heard no result by the deadline
 * (ADR 0266, "Reconcile by the journal"). It only reads: it sends no order and cannot act. The executor writes the order's `open`
 * record before its action and the `done` record after it, so once the Node's own clock is past the order's expiry the journal's
 * answer is final: a done record is the order's result, and no record means the Node never acted and never will.
 */
final readonly class ExecutorJournalLookup
{
    public const string Path = '/var/lib/orbit/executor/journal';

    /** The Node has not finished the order yet, or its clock has not passed the order's expiry: ask again later. */
    public const string Open = 'open';

    /** The Node's journal has no record of the order, and the order has expired there: the Node never acted. */
    public const string Absent = 'absent';

    private const float TimeoutSeconds = 10.0;

    public function __construct(private SshExecutor $ssh, private SshKeyProvider $keys, private KnownHostsStore $knownHosts) {}

    /**
     * The order's result line as the executor would have sent it, or Open, or Absent.
     *
     * @return array<string, mixed>|self::Open|self::Absent
     *
     * @throws RuntimeException when the Node cannot be read.
     */
    public function find(Operation $operation, string $action, int $publishedAtMs): array|string
    {
        $node = $operation->node_id === null ? null : Node::query()->find($operation->node_id);
        if ($node === null || ! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new RuntimeException('The Operation has no reachable Node.');
        }
        $key = sprintf('"operation":"%d","action":"%s","target":"%s"', $operation->id, $action, $operation->target_id);
        $result = $this->ssh->execute(new SshConnection($node->wireguard_ip, $node->user, 22, $this->keys->privateKeyPath(), $this->knownHosts->path()),
            new RemoteCommand(['sudo', '-n', 'sh', '-c', 'date +%s%3N && { grep -F -- "$1" "$2" || [ $? -eq 1 ]; }', 'orbit-executor-journal', $key, self::Path],
                maxOutputBytes: 262_144, timeout: self::TimeoutSeconds));
        if (! $result->succeeded() || $result->truncated) {
            throw new RuntimeException('The Node executor journal could not be read.');
        }
        $lines = explode("\n", trim($result->stdout));
        $nodeNow = (string) array_shift($lines);
        if (preg_match('/\A[0-9]{13}\z/D', $nodeNow) !== 1) {
            throw new RuntimeException('The Node did not report its clock.');
        }
        $open = false;
        foreach ($lines as $line) {
            try {
                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            if (! is_array($record) || ($record['operation'] ?? null) !== (string) $operation->id || ($record['action'] ?? null) !== $action
                || ($record['target'] ?? null) !== (string) $operation->target_id) {
                continue;
            }
            if (($record['record'] ?? null) === 'done' && is_array($outcome = $record['outcome'] ?? null)) {
                return self::reply($record, $outcome);
            }
            $open = $open || ($record['record'] ?? null) === 'open';
        }
        // The order was signed before it was published, so it expired on the Node once the Node's clock passed this bound.
        $expired = (int) $nodeNow > $publishedAtMs + ExecutorOrderSigner::ExpiresAfterMs;

        return $open || ! $expired ? self::Open : self::Absent;
    }

    /**
     * The executor's own reply for a done record: the outcome beside the order's key, `slept` reported as `done`.
     *
     * @param  array<string, mixed>  $record
     * @param  array<mixed>  $outcome
     * @return array<string, mixed>
     */
    private static function reply(array $record, array $outcome): array
    {
        $status = ($outcome['status'] ?? null) === 'slept' ? 'done' : ($outcome['status'] ?? null);

        return [...$outcome, 'operation' => $record['operation'], 'action' => $record['action'], 'target' => $record['target'],
            'status' => $status, 'repeat' => true];
    }
}
