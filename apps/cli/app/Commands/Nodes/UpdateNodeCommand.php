<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ProgressOutcome;
use App\Support\Console\ProgressState;
use App\Support\NodeSettingOptions;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Nodes\UpdateNodeRequest;
use Orbit\Sdk\Responses\Nodes\AppsSettings;
use Orbit\Sdk\Responses\Nodes\NodesResponse;
use Orbit\Sdk\Responses\Nodes\NodeUpdateResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class UpdateNodeCommand extends NodeCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'node:update
        {node? : Node ID or name}
        {--name= : New unique registry name}
        {--platform= : Platform of an unmanaged Node: linux, macos, or ipados}
        {--reboot-hold= : Hold fleet reboots: true or false}
        {--image-refresh-hold : Hold task VM image refreshes}
        {--no-image-refresh-hold : Clear the task VM image refresh hold}
        {--lan-ip= : LAN IPv4 address; an empty value clears it}
        {--apps-path= : Apps folder; an empty value restores the default}
        {--task-vms= : Task VM host settings as a JSON object, or null to remove them}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Change the name, platform, LAN address, reboot hold, image refresh hold, apps folder, or task VM host settings of a Node.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $reference = $this->argument('node');

        if ((! is_string($reference) || $reference === '') && ! $this->consoleMode()->mayPrompt) {
            return $this->renderGatewayFailure('node.reference_required', 'Node ID or name is required.');
        }

        $name = $this->option('name');
        $platform = $this->option('platform');
        $lanIp = $this->option('lan-ip');
        $rebootHold = $this->option('reboot-hold');
        $holdImageRefresh = (bool) $this->option('image-refresh-hold');
        $clearImageRefreshHold = (bool) $this->option('no-image-refresh-hold');
        $imageRefreshHold = $holdImageRefresh ? true : ($clearImageRefreshHold ? false : null);
        $appsPath = $this->option('apps-path');
        $taskVms = $this->option('task-vms');

        if (! is_string($name) && ! is_string($platform) && ! is_string($lanIp) && ! is_string($appsPath) && ! is_string($taskVms) && ! is_string($rebootHold) && $imageRefreshHold === null) {
            return $this->renderGatewayFailure('node.option_required', 'Provide at least one of --name, --platform, --lan-ip, --reboot-hold, --image-refresh-hold, --no-image-refresh-hold, --apps-path, or --task-vms.');
        }

        if ($holdImageRefresh && $clearImageRefreshHold) {
            return $this->renderGatewayFailure('node.image_refresh_hold_invalid', 'Use only one of --image-refresh-hold or --no-image-refresh-hold.');
        }

        if (is_string($rebootHold) && ! in_array($rebootHold, ['true', 'false'], true)) {
            return $this->renderGatewayFailure('node.reboot_hold_invalid', 'Reboot hold must be true or false.');
        }

        if ($name === '') {
            return $this->renderGatewayFailure('node.name_required', 'Name is required.');
        }

        if ($platform === '') {
            return $this->renderGatewayFailure('node.platform_required', 'Platform is required: linux, macos, or ipados.');
        }

        $host = is_string($taskVms) ? NodeSettingOptions::parseTaskVms($taskVms) : null;

        if ($host !== null && $host['ok'] === false) {
            return $this->renderGatewayFailure($host['code'], $host['message']);
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        if (! is_string($reference) || $reference === '') {
            $nodes = $this->sendWithProgress($connector, new ListNodesRequest, NodesResponse::class,
                ['List Nodes', 'Loading Nodes', 'Loaded Nodes']);

            if (! $nodes instanceof NodesResponse) {
                return self::FAILURE;
            }

            $rows = [];

            foreach ($nodes->nodes as $node) {
                $rows[$node->id] = [(string) $node->id, $node->name];
            }

            $reference = $this->commandPrompts()->selectEntity('Node', ['ID', 'Name'], $rows);
        }

        $nodeId = $this->resolveNodeId($connector, $reference);

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $idempotencyKey = $this->option('idempotency-key');
        $response = $this->sendWithProgress(
            $connector,
            new UpdateNodeRequest(
                nodeId: $nodeId,
                name: is_string($name) ? $name : null,
                platform: is_string($platform) ? $platform : null,
                hasApps: is_string($appsPath),
                apps: is_string($appsPath) ? new AppsSettings($appsPath === '' ? null : $appsPath) : null,
                hasTaskVms: $host !== null,
                taskVms: NodeSettingOptions::taskVms($host['value'] ?? null),
                idempotencyKey: is_string($idempotencyKey) ? $idempotencyKey : null,
                rebootHold: is_string($rebootHold) ? $rebootHold === 'true' : null,
                imageRefreshHold: $imageRefreshHold,
                hasLanIp: is_string($lanIp),
                lanIp: is_string($lanIp) && $lanIp !== '' ? $lanIp : null,
            ),
            NodeUpdateResponse::class,
            ['Update Node', 'Updating Node', 'Node updated'],
            static fn (NodeUpdateResponse $response): ProgressOutcome|ProgressState => $response->skipped === null
                ? ProgressState::Success
                : new ProgressOutcome(ProgressState::Skipped, "Skipped — already set ({$response->skipped})"),
        );

        if (! $response instanceof NodeUpdateResponse) {
            return self::FAILURE;
        }

        $node = $response->result;

        if ($node instanceof OperationResponse) {
            $profile = $this->activeGatewayProfile($repository);

            return $profile === null ? self::FAILURE
                : $this->followOperation($connector, $profile, $node, 'Update Node', 'Node update');
        }

        if ($this->option('json') === true) {
            $this->writeJson([...$node->toArray(), ...($response->skipped === null ? [] : ['skipped' => $response->skipped])]);

            return self::SUCCESS;
        }

        $this->writeHumanMessage($response->skipped === null ? "Node #{$node->id} [{$node->name}] updated." : "Node #{$node->id} [{$node->name}]: Skipped — already set ({$response->skipped})");
        $this->writeHumanMessage("Request ID: {$node->requestId}");

        return self::SUCCESS;
    }
}
