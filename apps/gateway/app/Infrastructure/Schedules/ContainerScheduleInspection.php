<?php

declare(strict_types=1);

namespace App\Infrastructure\Schedules;

use App\Data\Schedules\ScheduleLogsData;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Logs\LogReadLimit;
use App\Domain\Schedules\ScheduleTarget;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Schedules\ScheduleTrigger;
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

/** Reads bounded Schedule state and journals through the shared Swamp container reader. */
final readonly class ContainerScheduleInspection
{
    private const string Uuid = '[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}';

    public function __construct(private WebSocketClient $socket) {}

    public function read(Schedule $schedule, ScheduleTarget $target, int $lines): ScheduleLogsData
    {
        if ($schedule->trigger === ScheduleTrigger::Container
            && $target->instance?->runtime()->first()?->power_state !== InstanceRuntimePowerState::Running) {
            throw new ResourceOperationException('schedule.target_inactive', 'The Schedule container is not running; it was not woken.', 409);
        }
        $value = $this->inspect($schedule, $target, $lines);
        if (($value['error_code'] ?? null) === 'schedule.target_inactive') {
            throw new ResourceOperationException('schedule.target_inactive', 'The Schedule container is not running; it was not woken.', 409);
        }

        return new ScheduleLogsData(ValidatedData::string($value['output']), ($value['truncated'] ?? false) === true);
    }

    /** @return array<string,mixed> */
    public function observe(Schedule $schedule): array
    {
        $target = app(ScheduleTargetResolver::class)->forInspection($schedule);
        if ($target->instance?->runtime()->first()?->power_state !== InstanceRuntimePowerState::Running) {
            return ['state' => 'inactive', 'last_run_at' => null, 'last_run_status' => null];
        }

        try {
            return $this->inspect($schedule, $target, 1);
        } catch (ResourceOperationException) {
            // Every refusal of inspect() is a failed read. The record still answers; only the observed part is unreadable.
            return ['state' => 'unreadable'];
        }
    }

    /** @return array<string,mixed> */
    private function inspect(Schedule $schedule, ScheduleTarget $target, int $lines): array
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
                        || preg_match('/\Anckrtl-process-command-assets-'.self::Uuid.'-inspection-inspection-inspection-'.preg_quote($request, '/').'-value\z/D', $key) !== 1) {
                        $this->unavailable();
                    }
                    $value = ValidatedData::object(json_decode(app(SwampVaultExecutionCopy::class)->readSecret('orbit-control-inputs', $key), true, flags: JSON_THROW_ON_ERROR));

                    $output = ValidatedData::string($value['output']);
                    if (strlen($output) > LogReadLimit::Bytes) {
                        $this->unavailable();
                    }

                    if (! in_array($value['state'] ?? null, ['active', 'inactive', 'failed', 'unknown'], true)
                        || ! in_array($value['last_run_status'] ?? null, [null, 'success', 'error'], true)
                        || (isset($value['last_run_at']) && (! is_string($value['last_run_at'])
                            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/', $value['last_run_at']) !== 1))) {
                        $this->unavailable();
                    }

                    return $value;
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
            ...($schedule->trigger === ScheduleTrigger::Container
                ? ['container' => ['instance_id' => $target->instance?->id, ...($target->container ?? [])]] : []),
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($target->node),
            'read' => ['lines' => $lines, 'request' => $request]];
    }

    private function unavailable(): never
    {
        throw new ResourceOperationException('schedule.logs_failed', 'The migrated Schedule journal could not be read.', 503);
    }
}
