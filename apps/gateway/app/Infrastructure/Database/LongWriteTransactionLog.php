<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use Closure;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Names the code that holds SQLite's write lock too long. Transactions begin IMMEDIATE, so an outermost transaction
 * holds the write lock from BEGIN to its end; another writer waits busy_timeout (5 s), then fails "database is locked".
 * The failing writer is only the victim, so the holder is logged here once it ends.
 */
final class LongWriteTransactionLog
{
    /** Holds this long or longer are logged; well under busy_timeout, so a hold that can lock others out shows. */
    public const int ThresholdMilliseconds = 2_000;

    private ?float $began = null;

    /** The level of the timed transaction: 1 in production, deeper under a test's wrapping transaction. */
    private int $level = 0;

    private ?string $origin = null;

    /** @param  (Closure(): float)|null  $clock  monotonic milliseconds */
    public function __construct(private readonly ?Closure $clock = null) {}

    public function register(): void
    {
        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event): void {
            if ($this->began === null) {
                $this->began = $this->now();
                $this->level = $event->connection->transactionLevel();
                $this->origin = self::origin();
            }
            // Nested records run their callbacks before the outer record. Each needs this first callback.
            $event->connection->afterCommit(function () use ($event): void {
                if ($event->connection->transactionLevel() < $this->level) {
                    $this->ended('committed');
                }
            });
        });
        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
            if ($this->began === null || $event->connection->transactionLevel() >= $this->level) {
                return;
            }
            $this->ended('rolled_back');
        });
    }

    private function ended(string $outcome): void
    {
        if ($this->began === null) {
            return;
        }
        $held = (int) round($this->now() - $this->began);
        $origin = $this->origin;
        $this->began = $this->origin = null;
        if ($held >= self::ThresholdMilliseconds) {
            Log::warning('Long SQLite write transaction', ['held_ms' => $held, 'origin' => $origin, 'outcome' => $outcome]);
        }
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : hrtime(true) / 1_000_000;
    }

    /** The first application frame outside the database layer: the code that opened the transaction. */
    private static function origin(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $file = $frame['file'] ?? '';
            if ($file !== '' && ! str_contains($file, '/vendor/') && ! str_ends_with($file, 'LongWriteTransactionLog.php')) {
                return str_replace(base_path().'/', '', $file).':'.($frame['line'] ?? 0);
            }
        }

        return null;
    }
}
