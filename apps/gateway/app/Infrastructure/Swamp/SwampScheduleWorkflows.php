<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
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

    public function delete(string $workflowId): void
    {
        $this->request('workflow.delete', ['workflowIdOrName' => $workflowId], missingIsDone: true);
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
        $address = config('swamp.address');
        $ca = config('swamp.ca_path');
        $token = config('swamp.run_token');
        if (! is_string($address) || $address === '' || ! is_string($ca) || $ca === '' || ! is_string($token) || $token === '') {
            throw $this->unavailable();
        }
        $id = bin2hex(random_bytes(16));
        try {
            $this->socket->connect(new WebSocketEndpoint($address, 443, 'swamp.orbit', '/', $ca, $token), 15);
            $this->socket->send(['type' => $type, 'id' => $id, 'payload' => $payload]);
            $deadline = microtime(true) + 30;
            while ($this->socket->isConnected() && ($remaining = $deadline - microtime(true)) > 0) {
                foreach ($this->socket->receive(min(1.0, $remaining)) as $message) {
                    if (($message['id'] ?? null) !== $id) {
                        continue;
                    }
                    if (($message['type'] ?? null) === 'error') {
                        $error = is_array($message['error'] ?? null) ? $message['error'] : [];
                        if ($missingIsDone && ($error['code'] ?? null) === 'not_found') {
                            return [];
                        }
                        // Serve validates the workflow, including its cron trigger, before it saves it.
                        if (in_array($error['code'] ?? null, ['validation_failed', 'invalid_request', 'workflow_edit_failed', 'invalid_schedule'], true)) {
                            throw new ResourceOperationException('schedule.calendar_invalid', 'Swamp refused the Schedule calendar.', 422);
                        }
                        throw $this->unavailable();
                    }
                    $data = is_array($message['payload'] ?? null) ? ($message['payload']['data'] ?? null) : null;
                    if (($message['type'] ?? null) !== $type || ! is_array($data)) {
                        throw $this->unavailable();
                    }

                    return $data;
                }
            }
            throw $this->unavailable();
        } catch (ResourceOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->unavailable();
        } finally {
            $this->socket->close();
        }
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('swamp.schedule_publish_unavailable', 'Swamp did not confirm the Schedule workflow change.', 503);
    }
}
