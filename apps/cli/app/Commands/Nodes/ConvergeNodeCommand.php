<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\ProgressOutcome;
use App\Support\Console\ProgressState;
use Orbit\Sdk\Requests\Nodes\ConvergeNodeRequest;
use Orbit\Sdk\Responses\Fleet\NodeFootprintResponse;
use Orbit\Sdk\Responses\Nodes\NodeConvergeResponse;

final class ConvergeNodeCommand extends NodeCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'node:converge
        {node : Node ID or name}
        {--force : Re-apply every artifact, not only the ones whose digest changed}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Re-apply the Gateway-rendered Orbit footprint of a Node. It never changes an Instance or a role.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->resolveNodeId($connector, $this->argument('node'));

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $result = $this->sendWithProgress(
            $connector,
            new ConvergeNodeRequest($nodeId, $this->option('force') === true,
                is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null),
            NodeConvergeResponse::class,
            ['Converge Node footprint', 'Converging Node footprint', 'Node footprint request completed'],
            static fn (NodeConvergeResponse $response): ProgressOutcome => new ProgressOutcome(ProgressState::Success,
                $response->result instanceof NodeFootprintResponse ? 'Converged Node footprint' : 'Node footprint Operation recorded'),
        );

        if (! $result instanceof NodeConvergeResponse) {
            return self::FAILURE;
        }
        $result = $result->result;
        if ($result instanceof NodeFootprintResponse) {
            if ($this->option('json') === true) {
                $this->writeJson($result->toArray());

                return self::SUCCESS;
            }
            $this->writeHumanMessage($result->changed
                ? "Node #{$result->nodeId} [{$result->node}] footprint re-applied."
                : "Node #{$result->nodeId} [{$result->node}] footprint is current. Nothing changed.");
            ConsoleWriter::write($this->output, $this->humanRenderer()->table(
                ['Artifact', 'Result'],
                array_map(static fn (string $name, string $outcome): array => [$name, $outcome], array_keys($result->artifacts), $result->artifacts),
                'The Node has no Gateway-rendered footprint.',
            ));
            $this->writeHumanMessage('Digest: '.$result->digest);
            $this->writeHumanMessage("Request ID: {$result->requestId}");

            return self::SUCCESS;
        }

        $profile = $this->activeGatewayProfile($repository);

        return $profile === null ? self::FAILURE
            : $this->followOperation($connector, $profile, $result, 'Converge Node footprint', 'Node footprint');
    }
}
