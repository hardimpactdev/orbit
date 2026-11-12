<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;

final class ShowNodeCommand extends NodeCommand
{
    private const string INVALID_ID = 'node.id_invalid';

    #[\Override]
    protected $signature = 'node:show
        {node? : Numeric node ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show a node registered with the active gateway.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        if ($this->argument('node') === null && $this->consoleMode()->mayPrompt) {
            $nodes = $this->sendWithProgress($connector, new ListNodesRequest, NodesResponse::class,
                ['List Nodes', 'Loading Nodes', 'Loaded Nodes']);

            if (! $nodes instanceof NodesResponse) {
                return self::FAILURE;
            }

            $rows = [];
            foreach ($nodes->nodes as $candidate) {
                $rows[$candidate->id] = [(string) $candidate->id, $candidate->name];
            }

            $this->input->setArgument('node', (string) $this->commandPrompts()->selectEntity('Node', ['ID', 'Name'], $rows));
        }

        $nodeId = $this->positiveId('node', 'Node', self::INVALID_ID);

        if ($nodeId === null) {
            if ($this->option('json') !== true) {
                $this->writeHumanMessage('Code: '.self::INVALID_ID);
            }

            return self::FAILURE;
        }

        $node = $this->sendWithProgress($connector, new ShowNodeRequest($nodeId), NodeResponse::class, ['Show Node', 'Loading Node', 'Loaded Node']);

        if (! $node instanceof NodeResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($node->toArray());

            return self::SUCCESS;
        }

        $platform = implode(' ', array_filter([$node->platform, $node->architecture !== null ? "({$node->architecture})" : null]));
        $fields = [
            'ID' => $node->id,
            'Status' => $node->status,
            'Agent' => $node->agent?->label(),
            'Roles' => NodeOutput::roles($node->assignments),
            'SSH' => NodeOutput::sshEndpoint($node),
            'Cluster' => $node->clusterName,
            'WireGuard' => $node->wireguardIp,
            'LAN' => $node->lanIp,
            'Reboot hold' => $node->rebootHold ? 'yes' : 'no',
            'Image refresh hold' => $node->imageRefreshHold ? 'yes' : 'no',
            'WireGuard public key' => $node->wireguardPublicKey,
            'DNS server override' => $node->dnsServerOverride,
            'TLD' => NodeOutput::tld($node->tld),
            'Platform' => $platform,
            'Apps folder' => $node->settings?->apps?->path,
            'Access to' => NodeOutput::accessList($node->access->canAccess ?? []),
            'Accessible by' => NodeOutput::accessList($node->access->accessibleBy ?? []),
        ];

        if ($node->failedStep !== null || $node->errorCode !== null) {
            $fields['Failure'] = implode(' / ', array_filter([$node->failedStep, $node->errorCode], is_string(...)));
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Node: {$node->name}", $fields));
        $this->writeHumanMessage("Request ID: {$node->requestId}");

        return self::SUCCESS;
    }
}
