<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Gateway's count of its own serve handshakes, shared by every PHP process on the Gateway host (PHP-FPM, Horizon, Artisan).
 *
 * Serve admits 50 WebSocket upgrades from one address in a fixed window that opens at the first upgrade after the last window
 * ended (src/serve/rate_limiter.ts at db3f6373), for every token together, and the Gateway is one address. This ledger mirrors
 * that window with a margin of one second. Priority callers always connect: serve decides for them. A view read connects only
 * while the window holds fewer than `ViewShare` handshakes, so `Limit - ViewShare` stay for Operations whatever screens read.
 *
 * The ledger is one small JSON file under `ORBIT_HOME/cache`, outside every release folder, guarded by `flock`. The lock covers a
 * read and a write of that file only, never a network call. A lock not held within `LockMilliseconds`, a file that cannot be
 * opened, or any other error lets the caller connect as if there were no ledger (fail open), with one warning per process. A
 * corrupt or oversized file is replaced. A refused upgrade (429) marks the window full until its `Retry-After`; that blocks only
 * views. The first handshake of a new minute logs the counts of the minute before, by token and caller.
 */
final class SwampHandshakes
{
    public const int Limit = 50;

    /** Views connect while the window holds fewer handshakes than this; the other 30 are kept for priority callers. */
    public const int ViewShare = 20;

    /** Serve's window of 60 s plus one second for clock and transit drift. */
    public const int WindowSeconds = 61;

    public const int LockMilliseconds = 50;

    private const int MaxFileBytes = 8_192;

    private static bool $warned = false;

    /** How many upgrades serve refused with 429 in this process, and the `Retry-After` of the last one. */
    private int $refusals = 0;

    private ?int $lastRetryAfter = null;

    /** The `Retry-After` of the last view refused in this process, for the response of the read that asked. */
    private ?int $viewRetryAfter = null;

    public function __construct(private readonly string $path) {}

    public static function path(string $orbitHome): string
    {
        return rtrim($orbitHome, '/').'/cache/swamp-handshakes/ledger.json';
    }

    /**
     * Counts one handshake for `$caller` and connects `$socket`. A view over its share throws `SwampReadBusy` without connecting.
     *
     * @throws SwampReadBusy|WebSocketException
     */
    public function connect(WebSocketClient $socket, WebSocketEndpoint $endpoint, float $timeoutSeconds, SwampCaller $caller): void
    {
        $token = $this->token($endpoint);
        $wait = $this->admit($caller, $token);
        if ($wait !== null) {
            $this->viewRetryAfter = $wait;
            throw new SwampReadBusy($wait);
        }
        try {
            $socket->connect($endpoint, $timeoutSeconds);
        } catch (WebSocketException $exception) {
            if ($exception->getCode() === 429) {
                $this->refusals++;
                $this->lastRetryAfter = $exception->retryAfterSeconds;
                $this->refused($exception->retryAfterSeconds);
            }
            throw $exception;
        }
    }

    /** How many upgrades serve refused with 429 in this process so far. */
    public function refusals(): int
    {
        return $this->refusals;
    }

    /** Seconds to wait after the last refusal in this process: its `Retry-After` plus one, at most 61. */
    public function retryDelay(): int
    {
        return min(self::WindowSeconds, ($this->lastRetryAfter ?? self::WindowSeconds - 1) + 1);
    }

    public function viewRetryAfter(): ?int
    {
        return $this->viewRetryAfter;
    }

    /** Notes that this request answered a view from its cache because an earlier view was refused; it may read again after `$seconds`. */
    public function deferView(int $seconds): void
    {
        $this->viewRetryAfter = max($this->viewRetryAfter ?? 0, max(1, $seconds));
    }

    /** Adds `Retry-After` to a screen read's answer when a view read in this request yielded to Operations. The body keeps its shape. */
    public function announce(JsonResponse $response): JsonResponse
    {
        return $this->viewRetryAfter === null ? $response : $response->header('Retry-After', (string) $this->viewRetryAfter);
    }

    /** null when the caller may connect, else the seconds until a view may try again. */
    private function admit(SwampCaller $caller, string $token): ?int
    {
        $now = $this->now();
        $wait = null;
        $previous = $this->update(function (array $state) use ($caller, $token, $now, &$wait): array {
            if ($now >= $state['ends']) {
                [$state['ends'], $state['count']] = [$now + self::WindowSeconds, 0];
            }
            if ($caller->isView() && $state['count'] >= self::ViewShare) {
                $wait = max(1, (int) ceil($state['ends'] - $now));
                $state['views_refused']++;

                return $state;
            }
            $state['count']++;
            $key = $token.':'.$caller->value;
            $state['callers'][$key] = ($state['callers'][$key] ?? 0) + 1;

            return $state;
        });
        $this->report($previous);

        return $wait;
    }

    private function refused(?int $retryAfterSeconds): void
    {
        $now = $this->now();
        $this->update(static function (array $state) use ($now, $retryAfterSeconds): array {
            [$state['ends'], $state['count']] = [$now + max(1, min(self::WindowSeconds, $retryAfterSeconds ?? self::WindowSeconds)), self::Limit];

            return $state;
        });
    }

    /**
     * Applies `$change` to the ledger under its lock. Returns the counts of a minute that has just ended, if any.
     *
     * @param  \Closure(array{ends: float, count: int, minute: int, callers: array<string, int>, views_refused: int}): array{ends: float, count: int, minute: int, callers: array<string, int>, views_refused: int}  $change
     * @return array{minute: int, callers: array<string, int>, views_refused: int}|null
     */
    private function update(\Closure $change): ?array
    {
        $handle = null;
        $locked = false;
        try {
            $directory = dirname($this->path);
            if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
                throw new \RuntimeException('The ledger folder could not be made.');
            }
            $handle = @fopen($this->path, 'c+');
            if ($handle === false) {
                throw new \RuntimeException('The ledger could not be opened.');
            }
            $deadline = microtime(true) + self::LockMilliseconds / 1000;
            while (! ($locked = flock($handle, LOCK_EX | LOCK_NB))) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('The ledger lock was not free.');
                }
                usleep(1_000);
            }
            $state = $this->decode(stream_get_contents($handle, self::MaxFileBytes + 1));
            $minute = intdiv((int) floor($this->now()), 60);
            $previous = null;
            if ($state['minute'] !== $minute) {
                if ($state['callers'] !== [] || $state['views_refused'] > 0) {
                    $previous = ['minute' => $state['minute'], 'callers' => $state['callers'], 'views_refused' => $state['views_refused']];
                }
                [$state['minute'], $state['callers'], $state['views_refused']] = [$minute, [], 0];
            }
            $encoded = json_encode($change($state), JSON_THROW_ON_ERROR);
            if (! ftruncate($handle, 0) || ! rewind($handle) || fwrite($handle, $encoded) !== strlen($encoded)) {
                throw new \RuntimeException('The ledger could not be written.');
            }
            fflush($handle);

            return $previous;
        } catch (Throwable $exception) {
            if (! self::$warned) {
                self::$warned = true;
                Log::warning('The Swamp handshake ledger is unavailable; connections go ahead uncounted.', ['exception_type' => $exception::class]);
            }

            return null;
        } finally {
            if ($handle !== null && $handle !== false) {
                if ($locked) {
                    flock($handle, LOCK_UN);
                }
                fclose($handle);
            }
        }
    }

    /** @return array{ends: float, count: int, minute: int, callers: array<string, int>, views_refused: int} */
    private function decode(string|false $raw): array
    {
        $fresh = ['ends' => 0.0, 'count' => 0, 'minute' => 0, 'callers' => [], 'views_refused' => 0];
        if (! is_string($raw) || $raw === '' || strlen($raw) > self::MaxFileBytes) {
            return $fresh;
        }
        $state = json_decode($raw, true);
        if (! is_array($state) || ! is_numeric($state['ends'] ?? null) || ! is_int($state['count'] ?? null) || ! is_int($state['minute'] ?? null)
            || ! is_array($state['callers'] ?? null) || ! is_int($state['views_refused'] ?? null)) {
            return $fresh;
        }
        $callers = array_filter($state['callers'], static fn (mixed $count, mixed $key): bool => is_string($key) && is_int($count), ARRAY_FILTER_USE_BOTH);
        $ends = (float) $state['ends'];

        // A window can never end later than one window from now: a clock step back must not block views.
        return ['ends' => min($ends, $this->now() + self::WindowSeconds), 'count' => max(0, $state['count']), 'minute' => $state['minute'],
            'callers' => $callers, 'views_refused' => max(0, $state['views_refused'])];
    }

    /** @param array{minute: int, callers: array<string, int>, views_refused: int}|null $previous */
    private function report(?array $previous): void
    {
        if ($previous === null) {
            return;
        }
        $byToken = ['run' => 0, 'read' => 0];
        foreach ($previous['callers'] as $key => $count) {
            $token = explode(':', $key, 2)[0];
            $byToken[$token] = ($byToken[$token] ?? 0) + $count;
        }
        ksort($previous['callers']);
        Log::info('Swamp handshakes per minute', [
            'minute' => Date::createFromTimestampUTC($previous['minute'] * 60)->toIso8601ZuluString(),
            'run' => $byToken['run'], 'read' => $byToken['read'], 'by_caller' => $previous['callers'], 'views_refused' => $previous['views_refused'],
        ]);
    }

    /** The token's role, never the token: `read` for the read token, `run` for any other. */
    private function token(WebSocketEndpoint $endpoint): string
    {
        $read = config('swamp.read_token');

        return is_string($read) && $read !== '' && hash_equals($read, (string) $endpoint->bearerToken) ? 'read' : 'run';
    }

    private function now(): float
    {
        return Date::now()->getTimestampMs() / 1000;
    }
}
