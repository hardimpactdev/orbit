<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\PromptAborted;
use App\Support\GatewayFailureRenderer;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Requests\Instances\ShowInstanceRequest;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
use Orbit\Sdk\Requests\Processes\NodeProcessTarget;
use Orbit\Sdk\Responses\Instances\InstanceNodeServiceTargetResponse;
use Orbit\Sdk\Responses\Instances\InstanceResponse;
use Orbit\Sdk\Responses\Instances\InstancesResponse;
use Orbit\Sdk\Responses\Processes\ProcessesResponse;

abstract class NodeServiceTargetCommand extends GatewayCommand
{
    /** @param array<string, mixed> $details */
    #[\Override]
    protected function renderGatewayFailure(string $code, string $message, ?string $requestId = null, ?string $humanMessage = null, array $details = []): int
    {
        GatewayFailureRenderer::write($this, $code, $message, $requestId, $humanMessage, $details, includeCode: true);

        return self::FAILURE;
    }

    protected function instanceId(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): ?int
    {
        if ($this->argument('instance') !== null || ! $this->consoleMode()->mayPrompt) {
            return $this->positiveId('instance', 'Instance', 'instance.id_invalid');
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return null;
        }
        $instances = $this->sendWithProgress($connector, new ListInstancesRequest, InstancesResponse::class,
            ['List Instances', 'Loading Instances', 'Loaded Instances']);
        if (! $instances instanceof InstancesResponse) {
            return null;
        }
        $rows = [];
        foreach ($instances->instances as $instance) {
            $rows[$instance->id] = [(string) $instance->id, $instance->name, $instance->project->slug ?? '—', $instance->node->name ?? (string) $instance->nodeId];
        }

        return $this->selectRecord('Instance', ['ID', 'Name', 'Project', 'Node'], $rows, 'instance.id_invalid');
    }

    protected function processId(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors, int $instanceId): ?int
    {
        if (! $this->consoleMode()->mayPrompt) {
            $this->renderGatewayFailure('instance.node_service_process_required', 'Node Process ID is required.');

            return null;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return null;
        }
        $instance = $this->sendWithProgress($connector, new ShowInstanceRequest($instanceId), InstanceResponse::class,
            ['Resolve Instance', 'Loading Instance', 'Loaded Instance']);
        if (! $instance instanceof InstanceResponse) {
            return null;
        }
        $processes = $this->sendWithProgress($connector, new ListProcessesRequest(new NodeProcessTarget($instance->nodeId)), ProcessesResponse::class,
            ['List Node Processes', 'Loading Node Processes', 'Loaded Node Processes']);
        if (! $processes instanceof ProcessesResponse) {
            return null;
        }
        $rows = [];
        foreach ($processes->processes as $process) {
            $rows[$process->id] = [(string) $process->id, $process->name, $process->runtime];
        }

        return $this->selectRecord('Node Process', ['ID', 'Name', 'Runtime'], $rows, 'instance.node_service_process_required');
    }

    /** @param list<string> $headers
     * @param  array<int, list<string>>  $rows
     */
    protected function selectRecord(string $label, array $headers, array $rows, string $emptyCode): ?int
    {
        if ($rows === []) {
            $this->renderGatewayFailure($emptyCode, "No {$label} records are available.");

            return null;
        }
        try {
            $selected = $this->commandPrompts()->selectEntity($label, $headers, $rows);

            return is_int($selected) ? $selected : null;
        } catch (PromptAborted|ConsoleInterrupted $exception) {
            $this->renderGatewayFailure('input.cancelled', $exception->getMessage());

            return null;
        }
    }

    protected function environmentKey(string $option, string $label, string $requiredCode, ?string $other = null): ?string
    {
        $value = $this->stringOption($option);
        if ($value !== null || ! $this->consoleMode()->mayPrompt) {
            return $this->requiredStringOption($option, $label, $requiredCode);
        }
        try {
            $value = $this->commandPrompts()->run(fn (): TextPrompt => new TextPrompt($label, required: true,
                validate: static fn (string $key): ?string => preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,254}\z/D', $key) !== 1
                    ? 'Use up to 255 letters, digits and underscores, starting with a letter or underscore.'
                    : ($key === $other ? 'Choose a different environment key.' : null)));

            return is_string($value) ? $value : null;
        } catch (PromptAborted|ConsoleInterrupted) {
            $this->renderGatewayFailure('input.cancelled', 'Node service target input cancelled.');

            return null;
        }
    }

    protected function renderTarget(InstanceNodeServiceTargetResponse $target, string $operation): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($target->toArray());

            return self::SUCCESS;
        }
        $title = $target->changed ? "Node service target {$operation}." : 'Skipped — nothing to change (instance.node_service_target_unchanged).';
        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($title, [
            'Target ID' => $target->id, 'Instance ID' => $target->instanceId, 'Node ID' => $target->nodeId, 'Process ID' => $target->processId,
            'Protocol' => $target->protocol, 'Port' => $target->port, 'Host key' => $target->hostEnvKey,
            'Port key' => $target->portEnvKey, 'Changed' => $target->changed ? 'true' : 'false',
            'Request ID' => $target->requestId,
        ]));

        return self::SUCCESS;
    }
}
