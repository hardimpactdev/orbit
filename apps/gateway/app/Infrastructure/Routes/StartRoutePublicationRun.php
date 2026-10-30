<?php

declare(strict_types=1);

namespace App\Infrastructure\Routes;

use App\Domain\Routes\DirectRoutePublication;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Operation;
use App\Support\ValidatedData;
use Throwable;

/** One native Swamp start request. Never polls, retries or waits for completion. */
final readonly class StartRoutePublicationRun
{
    public const string Workflow = 'operation-route-publication';

    public function __construct(private WebSocketClient $socket) {}

    public function start(Operation $operation): void
    {
        app(DirectRoutePublication::class)->assertOperation($operation);
        $token = config('swamp.run_token');
        if (! is_string($token) || $token === '') {
            throw new ResourceOperationException('swamp.start_unavailable', 'The Route workflow start credential is not configured.', 503);
        }
        $id = bin2hex(random_bytes(16));
        $deadline = microtime(true) + 5;
        try {
            $this->socket->connect(new WebSocketEndpoint(ValidatedData::string(config('swamp.address')), 443, 'swamp.orbit', '/', ValidatedData::string(config('swamp.ca_path')), $token), 2);
            $this->socket->send(['type' => 'workflow.run', 'id' => $id, 'payload' => [
                'workflowIdOrName' => self::Workflow,
                'inputs' => ['operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id],
            ]]);
            while ($this->socket->isConnected() && ($remaining = $deadline - microtime(true)) > 0) {
                foreach ($this->socket->receive(min(0.2, $remaining)) as $message) {
                    if (($message['id'] ?? null) !== $id) {
                        continue;
                    }
                    $event = $message['event'] ?? null;
                    if (($message['type'] ?? null) === 'event' && is_array($event) && ($event['kind'] ?? null) === 'started'
                        && ! isset($event['parentRunId']) && ($event['workflowName'] ?? null) === self::Workflow && is_string($event['runId'] ?? null)) {
                        $operation->refresh();
                        $operation->update(['dispatched_at' => now(), 'state' => [...($operation->state ?? []), 'swamp_run_id' => $event['runId']]]);

                        return;
                    }
                    if (($message['type'] ?? null) === 'error') {
                        throw new ResourceOperationException('swamp.start_refused', 'Swamp refused the Route workflow start. Inspect Swamp before another admission.', 503);
                    }
                }
            }
            throw new ResourceOperationException('swamp.start_uncertain', 'Swamp did not confirm its start. Inspect native run history before another admission.', 503);
        } catch (ResourceOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ResourceOperationException('swamp.start_uncertain', 'Swamp did not confirm its start. Inspect native run history before another admission.', 503);
        } finally {
            $this->socket->close();
        }
    }
}
