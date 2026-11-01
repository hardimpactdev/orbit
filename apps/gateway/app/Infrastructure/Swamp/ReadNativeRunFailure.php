<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Operation;
use Throwable;

/** One authenticated history read. It never starts, resumes or hydrates native work. */
final readonly class ReadNativeRunFailure
{
    public function __construct(private WebSocketClient $socket) {}

    /** @return array{status:OperationStatus,failure:array{failed_step:string,detail:string,stderr_recorded:bool}|null,finished_at:?string}|null */
    public function read(Operation $operation, string $workflow, string $runId): ?array
    {
        $token = config('swamp.read_token');
        $address = config('swamp.address');
        $caPath = config('swamp.ca_path');
        if (! is_string($token) || $token === '' || ! is_string($address) || $address === '' || ! is_string($caPath) || $caPath === '') {
            return null;
        }
        $id = bin2hex(random_bytes(16));
        $deadline = microtime(true) + 2;
        try {
            $this->socket->connect(new WebSocketEndpoint($address, 443, 'swamp.orbit', '/', $caPath, $token), 0.5);
            $this->socket->send(['type' => 'workflow.history.get', 'id' => $id, 'payload' => ['workflowIdOrName' => $runId]]);
            while ($this->socket->isConnected() && ($remaining = $deadline - microtime(true)) > 0) {
                foreach ($this->socket->receive(min(0.2, $remaining)) as $message) {
                    if (($message['id'] ?? null) !== $id) {
                        continue;
                    }
                    $payload = $message['payload'] ?? null;
                    $run = is_array($payload) ? ($payload['data'] ?? null) : null;
                    $input = is_array($run) ? ($run['inputs'] ?? null) : null;
                    if (($message['type'] ?? null) !== 'workflow.history.get' || ! is_array($run) || ! is_array($input)
                        || ($run['id'] ?? null) !== $runId || ($run['workflowName'] ?? null) !== $workflow
                        || ! in_array($input['operation_id'] ?? null, [$operation->id, (string) $operation->id], true)
                        || ($input['kind'] ?? null) !== $operation->kind
                        || ! in_array($input['node_id'] ?? null, [$operation->node_id, (string) $operation->node_id], true)) {
                        return null;
                    }

                    $status = match ($run['status'] ?? null) {
                        'failed' => OperationStatus::Failed,
                        'interrupted' => OperationStatus::Interrupted,
                        default => null,
                    };

                    return $status === null ? null : ['status' => $status, 'failure' => app(NativeStepFailure::class)->fromRun($run),
                        'finished_at' => $this->completionTime($run['completedAt'] ?? null)];
                }
            }
        } catch (Throwable) {
            return null;
        } finally {
            $this->socket->close();
        }

        return null;
    }

    private function completionTime(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $value) !== 1) {
            return null;
        }
        $parsed = date_parse($value);

        return $parsed['error_count'] === 0 && $parsed['warning_count'] === 0 ? $value : null;
    }
}
