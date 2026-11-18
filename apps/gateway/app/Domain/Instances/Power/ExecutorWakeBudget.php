<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Infrastructure\Executor\ExecutorResultWait;
use Closure;
use Illuminate\Support\Facades\DB;
use PDOException;

final readonly class ExecutorWakeBudget
{
    public float $deadline;

    public int $receiptDeadline;

    public function __construct()
    {
        $seconds = ExecutorResultWait::boundSeconds();
        $this->deadline = hrtime(true) / 1e9 + $seconds;
        $this->receiptDeadline = (int) now()->format('Uu') + (int) round($seconds * 1e6);
    }

    public function left(): float
    {
        return $this->deadline - hrtime(true) / 1e9;
    }

    /** @template TValue
     * @param  Closure():TValue  $write
     * @return TValue|null
     */
    public function write(Closure $write): mixed
    {
        $remainingMs = (int) floor($this->left() * 1000);
        if ($remainingMs < 0) {
            return null;
        }

        $pdo = DB::connection()->getPdo();
        $statement = $pdo->query('PRAGMA busy_timeout');
        $previous = $statement === false ? 0 : (int) $statement->fetchColumn();
        $statement = null;
        $pdo->exec('PRAGMA busy_timeout = '.min($previous, $remainingMs));
        try {
            return $write();
        } catch (PDOException $exception) {
            if (in_array(((int) ($exception->errorInfo[1] ?? 0)) & 0xFF, [5, 6], true)) {
                return null;
            }

            throw $exception;
        } finally {
            $pdo->exec('PRAGMA busy_timeout = '.$previous);
        }
    }
}
