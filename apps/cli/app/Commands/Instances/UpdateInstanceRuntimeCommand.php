<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\PromptAborted;
use App\Support\GatewayFailureRenderer;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Requests\Instances\UpdateInstanceRuntimeRequest;
use Orbit\Sdk\Responses\Instances\InstancesResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class UpdateInstanceRuntimeCommand extends GatewayCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'instance:runtime:update
        {instance? : Numeric Instance ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Prepare one Instance’s private Node store without restarting Processes.';

    protected function writeOperationResult(OperationResponse $operation): void
    {
        $reason = $operation->result['error_message'] ?? null;
        if (! $operation->succeeded() && is_string($reason)) {
            $this->writeHumanMessage(mb_substr($reason, 0, 1000));
        }
        $status = $operation->result['status'] ?? null;
        $versions = $operation->result['versions'] ?? [];
        if (is_string($status)) {
            $versions = is_array($versions) ? array_filter($versions, is_string(...)) : [];
            $this->writeHumanMessage('Runtime store: '.$status.'. Node: '.implode(', ', $versions).'.');
            $this->writeHumanMessage('No Process was restarted. The next launch uses the prepared Node.');
            $this->writeHumanMessage('Restart a crashed or outdated vp-dev explicitly.');
        }
    }

    /** @param array<string, mixed> $details */
    #[\Override]
    protected function renderGatewayFailure(string $code, string $message, ?string $requestId = null, ?string $humanMessage = null, array $details = []): int
    {
        GatewayFailureRenderer::write($this, $code, $message, $requestId, $humanMessage, $details, includeCode: true);

        return self::FAILURE;
    }

    private function instanceId(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): ?int
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
        try {
            $selected = $this->commandPrompts()->selectEntity('Instance', ['ID', 'Name', 'Project', 'Node'], $rows);

            return is_int($selected) ? $selected : null;
        } catch (PromptAborted|ConsoleInterrupted $exception) {
            $this->renderGatewayFailure('input.cancelled', $exception->getMessage());

            return null;
        }
    }

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->instanceId($repository, $connectors);
        if ($instanceId === null) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, new UpdateInstanceRuntimeRequest($instanceId, bin2hex(random_bytes(16))), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, 'Update Instance runtime', 'Instance runtime update')
            : self::FAILURE;
    }
}
