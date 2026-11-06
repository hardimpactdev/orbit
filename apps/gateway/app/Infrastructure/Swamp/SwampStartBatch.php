<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Logs\LogRedactor;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The `workflow.run` requests of several runs on one WebSocket. It sends every request first and then reads the replies by request id,
 * so the setup that Swamp does before it answers (33–55 s observed) can overlap. Each request has its own confirmation window, counted
 * from its send, and its own outcome: a refusal or a lost reply ends only that request. A lost confirmation never proves that no run started.
 */
final readonly class SwampStartBatch
{
    public const int ConfirmationSeconds = 120;

    /** @var Closure(): float */
    private Closure $clock;

    /** @param (Closure():float)|null $clock */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * @param  array<int|string, array<string,mixed>>  $payloads  the `workflow.run` payload of each run, under the key of the caller
     * @return array<int|string, array{run_id:string,failed:bool}|ResourceOperationException> the confirmed run, or why it is not, under the same keys
     */
    public function run(WebSocketClient $socket, WebSocketEndpoint $endpoint, array $payloads): array
    {
        $results = [];
        $pending = [];
        foreach ($payloads as $key => $payload) {
            $id = bin2hex(random_bytes(16));
            $started = ($this->clock)();
            try {
                $socket->send(['type' => 'workflow.run', 'id' => $id, 'payload' => $payload]);
            } catch (Throwable $exception) {
                $this->warn($payload, $id, 'send', $started, $exception);
                $results[$key] = $this->uncertain();

                // A socket that cannot be written to is gone for every request after it.
                continue;
            }
            // Observed pre-dispatch setup took 32.7s and 55.3s; worker dispatch follows started.
            $pending[$id] = ['key' => $key, 'payload' => $payload, 'started' => $started, 'deadline' => $started + self::ConfirmationSeconds, 'confirmed' => null];
        }

        while ($pending !== []) {
            $now = ($this->clock)();
            foreach ($pending as $id => $request) {
                if ($now >= $request['deadline']) {
                    $this->warn($request['payload'], $id, 'receive', $request['started'], null);
                    $results[$request['key']] = $this->uncertain();
                    unset($pending[$id]);
                }
            }
            if ($pending === []) {
                break;
            }
            if (! $socket->isConnected()) {
                foreach ($pending as $id => $request) {
                    $this->warn($request['payload'], $id, 'receive', $request['started'], null);
                    $results[$request['key']] = $this->uncertain();
                }
                break;
            }
            $wait = max(0.0, min(0.2, min(array_column($pending, 'deadline')) - ($this->clock)()));
            try {
                $messages = $socket->receive($wait);
            } catch (Throwable $exception) {
                // A read that fails leaves every unanswered request unproven, as a lost reply does.
                foreach ($pending as $id => $request) {
                    $this->warn($request['payload'], $id, 'receive', $request['started'], $exception);
                    $results[$request['key']] = $this->uncertain();
                }
                break;
            }
            foreach ($messages as $message) {
                $id = $message['id'] ?? null;
                if (! is_string($id) || ! isset($pending[$id])) {
                    continue;
                }
                $outcome = $this->interpret($message, $pending[$id]['payload'], $endpoint);
                if ($outcome instanceof ResourceOperationException) {
                    $results[$pending[$id]['key']] = $outcome;
                    unset($pending[$id]);
                } elseif ($outcome !== null) {
                    $results[$pending[$id]['key']] = $outcome;
                    unset($pending[$id]);
                }
            }
        }

        return $results;
    }

    /**
     * What one reply says about its request: the confirmed run, a refusal, or nothing yet.
     *
     * @param  array<string,mixed>  $message
     * @param  array<string,mixed>  $payload
     * @return array{run_id:string,failed:bool}|ResourceOperationException|null
     */
    private function interpret(array $message, array $payload, WebSocketEndpoint $endpoint): array|ResourceOperationException|null
    {
        $event = $message['event'] ?? null;
        if (($message['type'] ?? null) === 'event' && is_array($event) && ! isset($event['parentRunId'])
            && ($event['workflowName'] ?? null) === $payload['workflowIdOrName']
            && is_string($event['runId'] ?? null) && Str::isUuid($event['runId'])
            && in_array($event['kind'] ?? null, ['started', 'failed', 'cancelled'], true)) {
            return ['run_id' => $event['runId'], 'failed' => $event['kind'] !== 'started'];
        }
        if (($message['type'] ?? null) === 'error') {
            $error = is_array($message['error'] ?? null) ? $message['error'] : $message;
            $redact = static function (mixed $value, int $limit) use ($endpoint): string {
                if (! is_string($value) || strlen($value) > 32768 || preg_match('//u', $value) !== 1) {
                    return '[withheld]';
                }

                $safe = app(LogRedactor::class)->redact($value, array_filter([$endpoint->bearerToken]));

                return Str::limit(preg_replace('/[\x00-\x1f\x7f]/u', ' ', $safe) ?? '[withheld]', $limit);
            };
            Log::warning('Swamp direct start refused', [
                'workflow' => $payload['workflowIdOrName'], 'remote_type' => 'error',
                'remote_code' => $redact($error['code'] ?? null, 100),
                'remote_message' => $redact($error['message'] ?? null, 2000),
            ]);

            return new ResourceOperationException('swamp.start_refused', 'Swamp refused the workflow start. Inspect Swamp before another admission.', 503);
        }

        return null;
    }

    /** @param array<string,mixed> $payload */
    private function warn(array $payload, string $id, string $stage, float $started, ?Throwable $exception): void
    {
        $inputs = $payload['inputs'] ?? null;
        Log::warning('Swamp direct start response unavailable', ['operation_id' => is_array($inputs) ? ($inputs['operation_id'] ?? null) : null,
            'workflow' => $payload['workflowIdOrName'], 'request_id' => $id, 'stage' => $stage,
            'elapsed_ms' => (int) round((($this->clock)() - $started) * 1000),
            'confirmation_seconds' => self::ConfirmationSeconds, 'exception_type' => $exception === null ? null : $exception::class]);
    }

    private function uncertain(): ResourceOperationException
    {
        return new ResourceOperationException('swamp.start_uncertain', 'Swamp did not confirm its start. A native run may exist; inspect Swamp before another admission.', 503);
    }
}
