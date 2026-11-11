<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\Swamp\SwampVaultExecutionCopy;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads a container Process's state or newest journal lines (docs/reference/development-containers.md#processes). The Node agent cannot
 * reach a container's units, so `process:logs` runs `operation-process-container` and waits for it. The journal lines go only to the
 * vault; the run publishes the unit's state and the vault key, which this read checks against its own request id.
 */
final readonly class ContainerProcessInspection
{
    public function __construct(private WebSocketClient $socket) {}

    /** @return array{state:string,output:string} */
    public function read(Process $process, ProcessTarget $target, string $action, int $lines): array
    {
        if ($target->container === null || ! in_array($action, ['status', 'logs'], true) || $lines < 1 || $lines > 1000) {
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
                    'operation_id' => 0, 'node_id' => $target->node->id, 'kind' => 'process.inspect',
                    'intent' => self::intent($process, $target, $action, $lines, $request),
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
                    $reference = ValidatedData::object(app(SwampCommandStep::class)->processInspection($run['id'], $request)['inspection']);
                    $key = ValidatedData::string($reference['key']);
                    $pattern = ValidatedData::string(app(DirectOperation::class)->definition('process.inspect')['execution_copies'][0]['key_pattern'] ?? null);
                    if (($reference['vault'] ?? null) !== 'orbit-control-inputs'
                        || preg_match('~'.$pattern.'~D', $key) !== 1 || ! str_ends_with($key, '-'.$request.'-value')) {
                        $this->unavailable();
                    }
                    $value = ValidatedData::object(json_decode(app(SwampVaultExecutionCopy::class)->readSecret('orbit-control-inputs', $key), true, flags: JSON_THROW_ON_ERROR));

                    return ['state' => ValidatedData::string($value['state']), 'output' => ValidatedData::string($value['output'])];
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
     * The read's intent: the Process's unit, its container, the Node's SSH execution and the read itself.
     *
     * @return array<string,mixed>
     */
    public static function intent(Process $process, ProcessTarget $target, string $action, int $lines, string $request): array
    {
        return ['control' => ['process_id' => $process->id, 'name' => app(SystemdProcessRenderer::class)->unitName($process)],
            'container' => $target->container, 'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($target->node),
            'read' => ['action' => $action, 'lines' => $lines, 'request' => $request]];
    }

    private function unavailable(): never
    {
        throw new ResourceOperationException('process.target_unavailable', 'The container Process could not be read.', 503);
    }
}
