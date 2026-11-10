<?php

declare(strict_types=1);

namespace App\Infrastructure\Schedules;

use App\Data\Schedules\ScheduleLogsData;
use App\Domain\Logs\LogReadLimit;
use App\Domain\Schedules\ScheduleTarget;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\Swamp\SwampVaultExecutionCopy;
use App\Models\Schedule;
use App\Support\ValidatedData;
use Illuminate\Support\Str;
use Throwable;

/** Reads a migrated Schedule's old unit and current command identifier from the Node journal through Swamp. */
final readonly class ContainerScheduleInspection
{
    public function __construct(private WebSocketClient $socket) {}

    public function read(Schedule $schedule, ScheduleTarget $target, int $lines): ScheduleLogsData
    {
        if ($target->container === null || $lines < 1 || $lines > 1000) {
            $this->unavailable();
        }
        $request = (string) Str::uuid();
        $id = bin2hex(random_bytes(16));
        $address = config('swamp.address');
        $ca = config('swamp.ca_path');
        $token = config('swamp.run_token');
        if (! is_string($address) || $address === '' || ! is_string($ca) || $ca === '' || ! is_string($token) || $token === '') {
            $this->unavailable();
        }
        try {
            $this->socket->connect(new WebSocketEndpoint($address, 443, 'swamp.orbit', '/', $ca, $token), 10);
            $this->socket->send(['type' => 'workflow.run', 'id' => $id, 'payload' => [
                'workflowIdOrName' => 'operation-process-container', 'inputs' => [
                    'operation_id' => 0, 'node_id' => $target->node->id, 'kind' => 'schedule.inspect',
                    'intent' => self::intent($schedule, $target, $lines, $request),
                    'execution_secrets' => (object) [],
                ],
            ]]);
            $deadline = microtime(true) + 120;
            while ($this->socket->isConnected() && ($remaining = $deadline - microtime(true)) > 0) {
                foreach ($this->socket->receive(min(1.0, $remaining)) as $message) {
                    if (($message['id'] ?? null) !== $id) {
                        continue;
                    }
                    if (($message['type'] ?? null) === 'error') {
                        $this->unavailable();
                    }
                    $event = $message['event'] ?? null;
                    $run = is_array($event) ? ($event['run'] ?? null) : null;
                    if (($message['type'] ?? null) !== 'event' || ! is_array($event) || isset($event['parentRunId'])
                        || ! in_array($event['kind'] ?? null, ['completed', 'cancelled'], true) || ! is_array($run)) {
                        continue;
                    }
                    if (($run['workflowName'] ?? null) !== 'operation-process-container' || ($run['status'] ?? null) !== 'succeeded'
                        || ! is_string($run['id'] ?? null) || ! Str::isUuid($run['id'])) {
                        $this->unavailable();
                    }
                    $this->socket->close();
                    $reference = ValidatedData::object(app(SwampCommandStep::class)->scheduleInspection($run['id'], $request)['inspection']);
                    $key = ValidatedData::string($reference['key']);
                    if (($reference['vault'] ?? null) !== 'orbit-control-inputs'
                        || preg_match('/\Anckrtl-process-command-assets-[a-f0-9-]{36}-inspection-inspection-'.preg_quote($request, '/').'-value\z/D', $key) !== 1) {
                        $this->unavailable();
                    }
                    $value = ValidatedData::object(json_decode(app(SwampVaultExecutionCopy::class)->readSecret('orbit-control-inputs', $key), true, flags: JSON_THROW_ON_ERROR));

                    $output = ValidatedData::string($value['output']);

                    return new ScheduleLogsData(LogReadLimit::journalInTimeOrder($output), strlen($output) >= LogReadLimit::Bytes);
                }
            }
            $this->unavailable();
        } catch (ResourceOperationException $error) {
            throw $error;
        } catch (Throwable) {
            $this->unavailable();
        } finally {
            $this->socket->close();
        }
    }

    /**
     * The read's intent: the Schedule's UUID, the Node's SSH execution and the request-bound read.
     *
     * @return array<string,mixed>
     */
    public static function intent(Schedule $schedule, ScheduleTarget $target, int $lines, string $request): array
    {
        return ['schedule' => ['id' => $schedule->id],
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($target->node),
            'read' => ['lines' => $lines, 'request' => $request]];
    }

    private function unavailable(): never
    {
        throw new ResourceOperationException('schedule.logs_failed', 'The migrated Schedule journal could not be read.', 503);
    }
}
