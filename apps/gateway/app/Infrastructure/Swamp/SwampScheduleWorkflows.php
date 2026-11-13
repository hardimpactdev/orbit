<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Support\ValidatedData;
use Throwable;

/** Writes one Schedule's own workflow and its trigger on serve through the official workflow frames. */
final readonly class SwampScheduleWorkflows
{
    public function __construct(private WebSocketClient $socket) {}

    /** Creates the workflow when it is new, writes its content, and returns its native id. */
    public function write(?string $workflowId, string $name, callable $content): string
    {
        if ($workflowId === null) {
            $created = $this->request('workflow.create', ['name' => $name]);
            $workflowId = $created['id'] ?? null;
            if (! is_string($workflowId) || ($created['name'] ?? null) !== $name) {
                throw $this->unavailable();
            }
        }
        $this->request('workflow.edit', ['workflowIdOrName' => $workflowId, 'content' => $content($workflowId)]);

        return $workflowId;
    }

    public function delete(string $name): void
    {
        $this->request('workflow.delete', ['workflowIdOrName' => $name], missingIsDone: true);
    }

    /** Deletes a vault key on serve; a key that is gone is done. */
    public function deleteSecret(string $vault, string $key): void
    {
        $this->deleteSecrets($vault, [$key]);
    }

    /**
     * Deletes vault keys on serve over one connection, one request after another; a key that is gone is done. Serve admits
     * at most 50 WebSocket connections from one address in a minute, so one connection per key (an Instance removal deletes
     * about 50) would refuse the Gateway's next workflow starts. The first failure stops the batch; deleting again is safe.
     *
     * @param  list<string>  $keys
     */
    public function deleteSecrets(string $vault, array $keys): void
    {
        if ($keys === []) {
            return;
        }
        $this->session(function () use ($vault, $keys): void {
            foreach ($keys as $key) {
                $this->exchange('vault.delete', ['vaultName' => $vault, 'key' => $key], missingIsDone: true);
                SwampVaultExecutionCopy::forget($vault, $key);
            }
        });
    }

    public function setTrigger(string $name, string $schedule): void
    {
        $data = $this->request('workflow.trigger.set', ['workflowName' => $name, 'schedule' => $schedule]);
        $entry = $data['entry'] ?? null;
        if (($data['workflowName'] ?? null) !== $name || ! is_array($entry) || ($entry['schedule'] ?? null) !== $schedule) {
            throw $this->unavailable();
        }
    }

    public function removeTrigger(string $name): void
    {
        $this->request('workflow.trigger.remove', ['workflowName' => $name], missingIsDone: true);
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function request(string $type, array $payload, bool $missingIsDone = false): array
    {
        return $this->session(fn (): array => $this->exchange($type, $payload, $missingIsDone));
    }

    /**
     * Runs `$requests` on one new connection and closes it afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $requests
     * @return T
     */
    private function session(callable $requests): mixed
    {
        $address = config('swamp.address');
        $ca = config('swamp.ca_path');
        $token = config('swamp.run_token');
        if (! is_string($address) || $address === '' || ! is_string($ca) || $ca === '' || ! is_string($token) || $token === '') {
            throw $this->unavailable();
        }
        try {
            $this->socket->connect(new WebSocketEndpoint($address, 443, 'swamp.orbit', '/', $ca, $token), 15);

            return $requests();
        } catch (ResourceOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->unavailable();
        } finally {
            $this->socket->close();
        }
    }

    /** One request on the open connection; replies are matched by `id`.
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function exchange(string $type, array $payload, bool $missingIsDone): array
    {
        $id = bin2hex(random_bytes(16));
        $this->socket->send(['type' => $type, 'id' => $id, 'payload' => $payload]);
        $deadline = microtime(true) + 30;
        while ($this->socket->isConnected() && ($remaining = $deadline - microtime(true)) > 0) {
            foreach ($this->socket->receive(min(1.0, $remaining)) as $message) {
                if (($message['id'] ?? null) !== $id) {
                    continue;
                }
                if (($message['type'] ?? null) === 'error') {
                    $error = is_array($message['error'] ?? null) ? $message['error'] : [];
                    // Pinned serve wraps workflow.delete's missing resource in its operation error.
                    $missingWorkflow = $type === 'workflow.delete' && is_string($payload['workflowIdOrName'] ?? null)
                        && ($error['code'] ?? null) === 'workflow_delete_failed'
                        && ($error['message'] ?? null) === 'Workflow not found: '.$payload['workflowIdOrName'];
                    if ($missingIsDone && (($error['code'] ?? null) === 'not_found' || $missingWorkflow)) {
                        return [];
                    }
                    // Serve validates the workflow, including its cron trigger, before it saves it.
                    if (in_array($error['code'] ?? null, ['workflow_trigger_set_failed', 'workflow_edit_failed'], true)) {
                        throw new ResourceOperationException('schedule.calendar_invalid', 'Swamp refused the Schedule calendar.', 422);
                    }
                    throw $this->unavailable();
                }
                $data = is_array($message['payload'] ?? null) ? ($message['payload']['data'] ?? null) : null;
                if (($message['type'] ?? null) !== $type || ! is_array($data)) {
                    throw $this->unavailable();
                }

                return ValidatedData::object($data);
            }
        }
        throw $this->unavailable();
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('swamp.schedule_publish_unavailable', 'Swamp did not confirm the Schedule workflow change.', 503);
    }
}
