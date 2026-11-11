<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\InstanceMigration\InstanceMigrateReadDeadline;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Operation;
use Illuminate\Support\Str;
use Throwable;

/**
 * One `workflow.cancel` request to Swamp on the run token; terminal projection stays native. Serve authorizes this request with
 * the `run` action on the run's workflow, which the Gateway already holds to start the run. Its HTTP cancel endpoint needs
 * `admin` on `access:*`, which is the superuser right, so the Gateway does not use it.
 */
final readonly class CancelSwampRun
{
    /** Serve waits for an aborted run to settle before it answers. */
    private const int ReplySeconds = 30;

    public function __construct(private WebSocketClient $socket) {}

    public function execute(Operation $operation): void
    {
        $run = $operation->state['swamp_run_id'] ?? null;
        $token = config('swamp.run_token');
        $address = config('swamp.address');
        $ca = config('swamp.ca_path');
        if (! is_string($run) || ! Str::isUuid($run) || ! is_string($token) || $token === ''
            || ! is_string($address) || $address === '' || ! is_string($ca) || $ca === '') {
            throw $this->unavailable();
        }
        if (InstanceMigrateReadDeadline::remaining(self::ReplySeconds) <= 0) {
            throw $this->unavailable();
        }
        $id = bin2hex(random_bytes(16));
        try {
            $this->socket->connect(new WebSocketEndpoint($address, 443, 'swamp.orbit', '/', $ca, $token), InstanceMigrateReadDeadline::remaining(15));
            $this->socket->send(['type' => 'workflow.cancel', 'id' => $id, 'payload' => ['runId' => $run, 'reason' => 'Orbit Operation cancellation requested']]);
            $deadline = microtime(true) + InstanceMigrateReadDeadline::remaining(self::ReplySeconds);
            while ($this->socket->isConnected() && ($remaining = InstanceMigrateReadDeadline::remaining($deadline - microtime(true))) > 0) {
                foreach ($this->socket->receive(min(0.5, $remaining)) as $message) {
                    if (($message['id'] ?? null) !== $id) {
                        continue;
                    }
                    $data = is_array($message['payload'] ?? null) ? ($message['payload']['data'] ?? null) : null;
                    if (($message['type'] ?? null) === 'workflow.cancel' && is_array($data) && ($data['runId'] ?? null) === $run
                        && in_array($data['status'] ?? null, ['cancellation_requested', 'cancelled'], true)) {
                        return;
                    }
                    // An error frame: serve refused, or the run is not cancellable. The terminal run stays authoritative.
                    throw $this->unavailable();
                }
            }
        } catch (ResourceOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->unavailable();
        } finally {
            $this->socket->close();
        }
        throw $this->unavailable();
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('swamp.cancel_unavailable', 'Native cancellation was not confirmed; the terminal run remains authoritative.', 503);
    }
}
