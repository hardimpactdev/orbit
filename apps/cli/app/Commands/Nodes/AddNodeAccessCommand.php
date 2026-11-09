<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ProgressOutcome;
use App\Support\Console\ProgressState;
use App\Support\GatewayFailureRenderer;
use Orbit\Sdk\Requests\Nodes\AddNodeAccessRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Responses\Nodes\AddedNodeAccessResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;

class AddNodeAccessCommand extends NodeCommand
{
    #[\Override]
    protected $signature = 'node:access:add
        {consumer? : Numeric consumer node ID}
        {serving? : Numeric serving node ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Allow one node to run commands on another node.';

    /** @param array<string, mixed> $details */
    #[\Override]
    protected function renderGatewayFailure(string $code, string $message, ?string $requestId = null, ?string $humanMessage = null, array $details = []): int
    {
        GatewayFailureRenderer::write($this, $code, $message, $requestId, $humanMessage, $details, includeCode: true);

        return self::FAILURE;
    }

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $ids = [];
        foreach (['consumer' => 'Consumer', 'serving' => 'Serving'] as $argument => $label) {
            $ids[$argument] = null;
            if ($this->argument($argument) !== null || ! $this->consoleMode()->mayPrompt) {
                $ids[$argument] = $this->positiveId($argument, $label, "node_access.{$argument}_id_invalid");
                if ($ids[$argument] === null) {
                    return self::FAILURE;
                }
            }
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        if (in_array(null, $ids, true)) {
            $nodes = $this->sendWithProgress($connector, new ListNodesRequest, NodesResponse::class,
                ['List Nodes', 'Loading Nodes', 'Loaded Nodes']);
            if (! $nodes instanceof NodesResponse) {
                return self::FAILURE;
            }

            $rows = [];
            foreach ($nodes->nodes as $node) {
                $rows[$node->id] = [(string) $node->id, $node->name];
            }
            foreach (['consumer' => 'Consumer', 'serving' => 'Serving'] as $argument => $label) {
                if ($ids[$argument] === null) {
                    $ids[$argument] = $this->commandPrompts()->selectEntity($label, ['ID', 'Name'], $rows);
                    if (! is_int($ids[$argument])) {
                        return self::FAILURE;
                    }
                }
            }
        }

        $access = $this->sendWithProgress(
            $connector,
            new AddNodeAccessRequest($ids['consumer'], $ids['serving']),
            AddedNodeAccessResponse::class,
            ['Add Node access', 'Adding Node access', 'Added Node access'],
            static fn (AddedNodeAccessResponse $response): ProgressOutcome => $response->alreadyExists
                ? new ProgressOutcome(ProgressState::Skipped, 'Skipped — already granted (node_access.exists)')
                : new ProgressOutcome(ProgressState::Success, 'Added Node access'),
        );

        if (! $access instanceof AddedNodeAccessResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson([
                ...$access->toArray(),
                ...($access->alreadyExists ? ['skipped' => 'node_access.exists'] : []),
            ]);

            return self::SUCCESS;
        }

        $message = $access->alreadyExists
            ? 'Skipped — already granted (node_access.exists)'
            : "Access from [{$access->consumerNode->name}] (#{$access->consumerNode->id}) to [{$access->servingNode->name}] (#{$access->servingNode->id}) added.";

        $this->writeHumanMessage($message);
        $this->writeHumanMessage("Request ID: {$access->requestId}");

        return self::SUCCESS;
    }
}
