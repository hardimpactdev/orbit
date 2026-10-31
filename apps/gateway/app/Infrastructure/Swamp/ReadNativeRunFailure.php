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

    public function status(Operation $operation, string $workflow, string $runId): ?OperationStatus
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

                    return match ($run['status'] ?? null) {
                        'failed' => OperationStatus::Failed,
                        'interrupted' => OperationStatus::Interrupted,
                        default => null,
                    };
                }
            }
        } catch (Throwable) {
            return null;
        } finally {
            $this->socket->close();
        }

        return null;
    }
}
