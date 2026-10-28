<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Nodes\SetNodeWireGuardKeyRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;

final class SetNodeWireGuardKeyCommand extends NodeCommand
{
    use FollowsOperations;
    #[\Override]
    protected $signature = 'node:wireguard-key
        {node? : Node ID or name}
        {public-key? : Canonical WireGuard public key}
        {--idempotency-key= : Reuse a key to retry this operation start}
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

        $operation = $this->sendWithProgress($connector,
            new SetNodeWireGuardKeyRequest($nodeId, $publicKey,
                is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null),
            OperationResponse::class, ['Set Node WireGuard key', 'Setting Node WireGuard key', 'Set Node WireGuard key']);
        if (! $operation instanceof OperationResponse) {
            return self::FAILURE;
        }
        $profile = $this->activeGatewayProfile($repository);

        return $profile === null ? self::FAILURE
            : $this->followOperation($connector, $profile, $operation, 'Set Node WireGuard key', 'Node WireGuard key');
    }

    private function validKey(string $value): bool
    {
        $decoded = base64_decode($value, strict: true);

        return is_string($decoded) && strlen($decoded) === 32 && base64_encode($decoded) === $value;
    }
}
