<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Fork the booted test application, with a fresh connection to a private committed database. */
final class ForkedGatewayProcess
{
    private ?int $pid = null;

    private ?int $exitCode = null;

    /** @var resource|null */
    private mixed $socket = null;

    /** @param Closure(resource): int $operation */
    public function __construct(
        private readonly string $database,
        private readonly string $output,
        private readonly Closure $operation,
    ) {}

    public function start(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Could not open the Gateway barrier sockets.');
        }
        [$parent, $child] = $sockets;
        stream_set_timeout($parent, 5);
        stream_set_timeout($child, 5);
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Could not fork a Gateway contender.');
        }
        if ($pid === 0) {
            fclose($parent);
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            ob_start(function (string $contents): string {
                file_put_contents($this->output, $contents, FILE_APPEND);

                return '';
            });
            try {
                config([
                    'database.connections.concurrency_probe' => [
                        ...config()->array('database.connections.sqlite'), 'database' => $this->database,
                    ],
                    'queue.batching.database' => 'concurrency_probe',
                    'queue.failed.database' => 'concurrency_probe',
                ]);
                DB::setDefaultConnection('concurrency_probe');
                DB::purge('concurrency_probe');
                // Open before announcing readiness, so SQLite's connection PRAGMAs cannot race a write.
                DB::connection()->getPdo();
                exit(($this->operation)($child));
            } catch (Throwable $exception) {
                file_put_contents($this->output.'.error', (string) $exception);
                exit(1);
            }
        }
        fclose($child);
        $this->socket = $parent;
        $this->pid = $pid;
    }

    public function run(): void
    {
        $this->start();
        $this->wait();
    }

    public function wait(): void
    {
        if ($this->pid !== null && $this->exitCode === null) {
            if (pcntl_waitpid($this->pid, $status) !== $this->pid) {
                throw new RuntimeException('Could not reap the Gateway contender.');
            }
            $this->recordExit($status);
        }
    }

    public function isRunning(): bool
    {
        if ($this->pid === null || $this->exitCode !== null) {
            return false;
        }
        $result = pcntl_waitpid($this->pid, $status, WNOHANG);
        if ($result === 0) {
            return true;
        }
        if ($result !== $this->pid) {
            throw new RuntimeException('Could not inspect the Gateway contender.');
        }
        $this->recordExit($status);

        return false;
    }

    public function getExitCode(): ?int
    {
        $this->isRunning();

        return $this->exitCode;
    }

    public function isSuccessful(): bool
    {
        return $this->getExitCode() === 0;
    }

    public function getOutput(): string
    {
        return is_file($this->output) ? (string) file_get_contents($this->output) : '';
    }

    public function getErrorOutput(): string
    {
        return is_file($this->output.'.error') ? (string) file_get_contents($this->output.'.error') : '';
    }

    public function send(string $message): void
    {
        self::write($this->socket(), $message);
    }

    public function receive(): string
    {
        return self::read($this->socket());
    }

    /** @param resource $socket */
    public static function write(mixed $socket, string $message): void
    {
        if (fwrite($socket, $message."\n") !== strlen($message) + 1) {
            throw new RuntimeException('Could not acknowledge the Gateway barrier.');
        }
    }

    /** @param resource $socket */
    public static function read(mixed $socket): string
    {
        $line = fgets($socket);
        if ($line === false) {
            throw new RuntimeException('The Gateway contender exited or its barrier timed out.');
        }

        return rtrim($line, "\r\n");
    }

    public function stop(): void
    {
        if ($this->pid !== null && $this->isRunning()) {
            posix_kill($this->pid, SIGKILL);
            $this->wait();
        }
        if (is_resource($this->socket)) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /** @return resource */
    private function socket(): mixed
    {
        if (! is_resource($this->socket)) {
            throw new RuntimeException('The Gateway contender has no open barrier.');
        }

        return $this->socket;
    }

    private function recordExit(int $status): void
    {
        $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 128 + pcntl_wtermsig($status);
        if ($code === false) {
            throw new RuntimeException('The Gateway contender has no exit status.');
        }
        $this->exitCode = $code;
    }
}
