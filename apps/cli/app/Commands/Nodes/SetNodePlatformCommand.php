<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Laravel\Prompts\SelectPrompt;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Nodes\SetNodePlatformRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;

final class SetNodePlatformCommand extends NodeCommand
{
    #[\Override]
    protected $signature = 'node:platform
        {node? : Node ID or name}
        {platform? : linux, macos, or ipados}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Set the platform of an unmanaged Node.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $reference = $this->argument('node');
        $platform = $this->argument('platform');
        $mayPrompt = $this->consoleMode()->mayPrompt;

        if ((! is_string($reference) || $reference === '') && ! $mayPrompt) {
            $this->renderGatewayFailure('node.reference_required', 'Node ID or name is required.');

            return self::FAILURE;
        }

        if ((! is_string($platform) || $platform === '') && ! $mayPrompt) {
            $this->renderGatewayFailure('node.platform_required', 'Platform is required: linux, macos, or ipados.');

            return self::FAILURE;
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

        if (! is_string($platform) || $platform === '') {
            $platform = $this->commandPrompts()->run(static fn (): SelectPrompt => new SelectPrompt('Platform', ['linux', 'macos', 'ipados']));
        }

        if (! is_string($platform)) {
            return self::FAILURE;
        }

        $node = $this->sendWithProgress($connector, new SetNodePlatformRequest($nodeId, $platform), NodeResponse::class,
            ['Set Node platform', 'Setting Node platform', 'Set Node platform']);

        if (! $node instanceof NodeResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($node->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Platform set to {$node->platform} for Node #{$node->id} [{$node->name}].");
        $this->writeHumanMessage("Request ID: {$node->requestId}");

        return self::SUCCESS;
    }
}
