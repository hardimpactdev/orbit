<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\PromptAborted;
use Orbit\Sdk\Requests\Nodes\ListNodeRolesRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Responses\Nodes\NodeRolesResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;

final class ListNodeRolesCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:role:list
        {node? : Positive numeric Node ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List role assignments for one node.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $selectNode = $this->argument('node') === null && $this->consoleMode()->mayPrompt;
        $nodeId = $selectNode ? null : $this->positiveId(
            'node', 'Node', 'node.id_invalid', 'Node ID must be a positive integer. (node.id_invalid)',
        );

        if (! $selectNode && $nodeId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        if ($selectNode) {
            $nodes = $this->sendWithProgress($connector, new ListNodesRequest, NodesResponse::class,
                ['List Nodes', 'Loading Nodes', 'Loaded Nodes']);

            if (! $nodes instanceof NodesResponse) {
                return self::FAILURE;
            }

            $rows = [];
            foreach ($nodes->nodes as $node) {
                $rows[$node->id] = [(string) $node->id, $node->name];
            }

            try {
                $nodeId = (int) $this->commandPrompts()->selectEntity('Node', ['ID', 'Name'], $rows);
            } catch (PromptAborted) {
                return $this->renderGatewayFailure('node.id_invalid', 'No Node was selected.');
            }
        }

        $response = $this->sendWithProgress($connector, new ListNodeRolesRequest($nodeId), NodeRolesResponse::class, ['List Node roles', 'Loading Node roles', 'Loaded Node roles']);

        if (! $response instanceof NodeRolesResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        if ($response->assignments === []) {
            $this->writeHumanMessage('No roles.');
            $this->writeHumanMessage("Request ID: {$response->requestId}");

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($response->assignments as $assignment) {
            $rows[] = [
                $assignment->id,
                $assignment->role,
                $assignment->status,
                $assignment->failedStep ?? null,
                $assignment->errorCode ?? null,
            ];
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(['ID', 'Role', 'Status', 'Failed step', 'Error code'], $rows));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
