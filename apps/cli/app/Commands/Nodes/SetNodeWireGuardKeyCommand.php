<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Nodes\SetNodeWireGuardKeyRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;

final class SetNodeWireGuardKeyCommand extends NodeCommand
{
    #[\Override]
    protected $signature = 'node:wireguard-key
        {node? : Node ID or name}
        {public-key? : Canonical WireGuard public key}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Set an unmanaged Node\'s WireGuard public key and reconverge the hub.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $reference = $this->argument('node');
        $publicKey = $this->argument('public-key');
        $mayPrompt = $this->consoleMode()->mayPrompt;

        if ((! is_string($reference) || $reference === '') && ! $mayPrompt) {
            $this->renderGatewayFailure('node.reference_required', 'Node ID or name is required.');

            return self::FAILURE;
        }

        if ((! is_string($publicKey) || $publicKey === '') && ! $mayPrompt) {
            $this->renderGatewayFailure('node.wireguard_key_required', 'WireGuard public key is required.');

            return self::FAILURE;
        }

        if (is_string($publicKey) && $publicKey !== '' && ! $this->validKey($publicKey)) {
            $this->renderGatewayFailure('validation.failed', 'The WireGuard public key must be canonical base64 for exactly 32 bytes.');

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

        if (! is_string($publicKey) || $publicKey === '') {
            $publicKey = $this->commandPrompts()->run(fn (): TextPrompt => new TextPrompt(
                label: 'WireGuard public key',
                required: true,
                validate: fn (string $value): ?string => $this->validKey($value) ? null : 'The WireGuard public key must be canonical base64 for exactly 32 bytes.',
            ));
        }

        if (! is_string($publicKey)) {
            return self::FAILURE;
        }

        $node = $this->sendWithProgress($connector, new SetNodeWireGuardKeyRequest($nodeId, $publicKey), NodeResponse::class,
            ['Set Node WireGuard key', 'Setting Node WireGuard key', 'Set Node WireGuard key']);

        if (! $node instanceof NodeResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($node->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("WireGuard public key set for Node #{$node->id} [{$node->name}].");
        $this->writeHumanMessage("Request ID: {$node->requestId}");

        return self::SUCCESS;
    }

    private function validKey(string $value): bool
    {
        $decoded = base64_decode($value, strict: true);

        return is_string($decoded) && strlen($decoded) === 32 && base64_encode($decoded) === $value;
    }
}
