<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\TerminalText;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\RemoveNodeAccessRequest;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodeAccessMutationResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class RemoveNodeAccessCommand extends NodeCommand
{
    use FollowsOperations;
    #[\Override]
    protected $signature = 'node:access:remove
        {consumer : Numeric consumer node ID}
        {serving : Numeric serving node ID}
        {--force : Skip the destructive confirmation prompt}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove one node access edge from the active gateway.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $consumerId = $this->positiveId('consumer', 'Consumer', 'node_access.consumer_id_invalid');

        if ($consumerId === null) {
            return self::FAILURE;
        }

        $servingId = $this->positiveId('serving', 'Serving', 'node_access.serving_id_invalid');

        if ($servingId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        if (! $this->existingNode($connector, $consumerId) instanceof NodeResponse) {
            return self::FAILURE;
        }

        if (! $this->existingNode($connector, $servingId) instanceof NodeResponse) {
            return self::FAILURE;
        }

        if (! $this->confirmed($consumerId, $servingId)) {
            return self::FAILURE;
        }

        $access = $this->sendWithProgress(
            $connector,
            new RemoveNodeAccessRequest($consumerId, $servingId,
                is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null),
            NodeAccessMutationResponse::class,
            ['Remove Node access', 'Removing Node access', 'Removed Node access'],
        );

        if (! $access instanceof NodeAccessMutationResponse) {
            return self::FAILURE;
        }
        $access = $access->result;
        if ($access instanceof OperationResponse) {
            $intent = $access->input['access'] ?? null;
            if (is_array($intent) && ($intent['self_lockout'] ?? false) === true) {
                if ($this->option('json') === true) {
                    $this->writeJson($access->toArray());
                } else {
                    $this->writeHumanMessage("Gateway access removal queued as Operation #{$access->id}.");
                    $this->writeHumanMessage('This Node will lose Gateway access. Check stream revocation from another authorized Node.');
                    $this->writeHumanMessage("Request ID: {$access->requestId}");
                }

                return self::SUCCESS;
            }
            $profile = $this->activeGatewayProfile($repository);

            return $profile === null ? self::FAILURE
                : $this->followOperation($connector, $profile, $access, 'Remove Node access', 'Node access');
        }

        if ($this->option('json') === true) {
            $this->writeJson($access->toArray());

            return self::SUCCESS;
        }

        $message = $access->alreadyAbsent
            ? "Access from [{$access->consumerNode->name}] (#{$access->consumerNode->id}) to [{$access->servingNode->name}] (#{$access->servingNode->id}) was already absent."
            : "Access from [{$access->consumerNode->name}] (#{$access->consumerNode->id}) to [{$access->servingNode->name}] (#{$access->servingNode->id}) removed.";

        $this->writeHumanMessage($message);

        if ($access->selfLockout) {
            ConsoleWriter::write($this->output, TerminalText::style(implode("\n", TerminalText::wrap('Warning: This node no longer has Gateway access.', $this->consoleMode()->columns)), 'orange', $this->consoleMode()->decorated).\PHP_EOL);
        }

        $this->writeHumanMessage("Request ID: {$access->requestId}");

        return self::SUCCESS;
    }

    private function existingNode(GatewayConnector $connector, int $nodeId): ?NodeResponse
    {
        $node = $this->sendWithProgress($connector, new ShowNodeRequest($nodeId), NodeResponse::class, ['Resolve Node', 'Loading Node', 'Loaded Node']);

        return $node instanceof NodeResponse ? $node : null;
    }

    private function confirmed(int $consumerId, int $servingId): bool
    {
        return $this->confirmAction(
            "Remove access from node #{$consumerId} to node #{$servingId}?",
            'Node access removal cancelled.',
            option: 'force',
            requiredCode: 'node_access.confirmation_required',
            requiredMessage: 'Use --force to confirm node access removal.',
        );
    }
}
