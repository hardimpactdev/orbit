<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Publish a schedule definition (workflow, cadence and inputs) through the official workflow.trigger.set frame. A definition that serve
 * confirmed within the last day is not sent again: each frame rewrites serve.yaml and registers the schedule again, which costs serve CPU.
 * The memo expires, so a definition that serve lost is sent again within a day; a lost memo costs one repeat.
 */
final readonly class SwampSchedulePublisher
{
    public function __construct(private DirectOperation $direct, private WebSocketClient $socket) {}

    /** The family owns capture, revision, cadence and commit-time invalidation.
     * @param  array<string,mixed>  $inputs  Small non-secret baseline inputs; private values stay in named vault copies.
     */
    public function publish(string $workflowId, string $schedule, array $inputs): void
    {
        $definition = $this->direct->scheduledResultDefinitions()[$workflowId] ?? null;
        $address = config('swamp.address');
        $ca = config('swamp.ca_path');
        $token = config('swamp.run_token');
        if ($definition === null || trim($schedule) === '' || ! is_string($address) || $address === ''
            || ! is_string($ca) || $ca === '' || ! is_string($token) || $token === '') {
            throw $this->unavailable();
        }
        $name = $definition['workflow'];
        $memo = 'swamp:schedule-definition:'.hash('sha256', $address).':'.$workflowId;
        $hash = hash('sha256', json_encode([$name, $schedule, $inputs], JSON_THROW_ON_ERROR));
        if (Cache::get($memo) === $hash) {
            return;
        }
        $id = bin2hex(random_bytes(16));
        $confirmed = false;
        try {
            // Match the JSON data representation used by the real socket.
            $expectedInputs = json_decode(json_encode($inputs, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            $this->socket->connect(new WebSocketEndpoint($address, 443, 'swamp.orbit', '/', $ca, $token), 15);
            $this->socket->send(['type' => 'workflow.trigger.set', 'id' => $id,
                'payload' => ['workflowName' => $name, 'schedule' => $schedule, 'inputs' => $inputs === [] ? (object) [] : $inputs]]);
            $deadline = microtime(true) + 30;
            while ($this->socket->isConnected() && ($remaining = $deadline - microtime(true)) > 0) {
                foreach ($this->socket->receive(min(1.0, $remaining)) as $message) {
                    if (($message['id'] ?? null) !== $id) {
                        continue;
                    }
                    $payload = $message['payload'] ?? null;
                    $data = is_array($payload) ? ($payload['data'] ?? null) : null;
                    $entry = is_array($data) ? ($data['entry'] ?? null) : null;
                    if (($message['type'] ?? null) !== 'workflow.trigger.set' || ! is_array($data) || ! is_array($entry)
                        || ($data['workflowName'] ?? null) !== $name || ($entry['schedule'] ?? null) !== $schedule
                        || ($entry['inputs'] ?? []) !== $expectedInputs) {
                        throw $this->unavailable();
                    }
                    $confirmed = true;

                    break 2;
                }
            }
        } catch (Throwable) {
            throw $this->unavailable();
        } finally {
            $this->socket->close();
        }
        if (! $confirmed) {
            throw $this->unavailable();
        }
        Cache::put($memo, $hash, now()->addDay());
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('swamp.schedule_publish_unavailable', 'The native schedule input publication was not confirmed.', 503);
    }
}
