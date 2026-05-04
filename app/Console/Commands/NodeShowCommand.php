<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Node;
use App\Services\Gateway\GatewayRequestSender;
use App\Services\Gateway\Requests\ShowNodeRequest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Signature('node:show
    {name? : Node name to inspect}
    {--json : Output JSON}')]
#[Description('Show node details from the gateway registry')]
class NodeShowCommand extends Command
{
    public function handle(): int
    {
        $callerRole = $this->callerRole();

        if ($callerRole === 'unknown') {
            return $this->failCommand(
                code: 'local_context_invalid',
                message: 'Local node role setting is invalid.',
                meta: [
                    'setting' => 'general.local_node_role',
                    'reason' => 'unsupported_value',
                    'caller_role' => 'unknown',
                ],
            );
        }

        $name = $this->resolveName();

        if ($name === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Node name is required.',
                meta: ['field' => 'name'],
            );
        }

        if ($callerRole !== 'gateway') {
            try {
                $gatewayData = $this->fetchFromGateway($name);

                $payload = ['node' => $this->restructureGatewayData($gatewayData)];

                if ($this->wantsJson()) {
                    return $this->jsonSuccess($payload);
                }

                $this->renderHuman($payload['node']);

                return self::SUCCESS;
            } catch (\Throwable) {
                return $this->failCommand(
                    code: 'gateway_unavailable',
                    message: 'Gateway connection is required to show node details.',
                    meta: [],
                );
            }
        }

        $node = Node::query()
            ->where('name', $name)
            ->where('status', 'active')
            ->first();

        if (! $node instanceof Node) {
            return $this->failCommand(
                code: 'node.not_found',
                message: "Node '{$name}' not found or not visible.",
                meta: ['name' => $name],
            );
        }

        $payload = ['node' => $this->nodePayload($node)];

        if ($this->wantsJson()) {
            return $this->jsonSuccess($payload);
        }

        $this->renderHuman($payload['node']);

        return self::SUCCESS;
    }

    private function callerRole(): string
    {
        $localRole = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->value('role');

        if (! is_string($localRole) || $localRole === '') {
            return 'control';
        }

        if (! in_array($localRole, ['gateway', 'app', 'control'], true)) {
            return 'unknown';
        }

        return $localRole;
    }

    private function resolveName(): ?string
    {
        $name = $this->argument('name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $defaultRecord = DB::table('local_node_defaults')->first();

        if ($defaultRecord !== null && is_string($defaultRecord->default_node_name) && $defaultRecord->default_node_name !== '') {
            return $defaultRecord->default_node_name;
        }

        $localName = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->value('name');

        if (is_string($localName) && $localName !== '') {
            return $localName;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchFromGateway(string $name): array
    {
        $response = GatewayRequestSender::make()->send(new ShowNodeRequest($name));

        if (! $response->isSuccess()) {
            throw new RuntimeException($response->errorMessage() ?? 'Gateway request failed.');
        }

        return $response->data();
    }

    /**
     * @param  array<string, mixed>  $gatewayData
     * @return array<string, mixed>
     */
    private function restructureGatewayData(array $gatewayData): array
    {
        return [
            'name' => $gatewayData['name'] ?? '',
            'role' => $gatewayData['role'] ?? '',
            'status' => $gatewayData['status'] ?? 'active',
            'environment' => $gatewayData['environment'] ?? null,
            'platform' => $gatewayData['platform'] ?? 'unknown',
            'addresses' => [
                'wireguard' => $gatewayData['wireguard_address']
                    ?? ($gatewayData['addresses']['wireguard'] ?? ($gatewayData['host'] ?? '')),
            ],
            'agent_ide' => [
                'adapter' => null,
                'source' => 'default',
            ],
            'grants' => [
                'consuming_nodes' => [],
                'serving_nodes' => [],
            ],
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     role: string,
     *     status: string,
     *     environment: string|null,
     *     platform: string,
     *     addresses: array{wireguard: string},
     *     agent_ide: array{adapter: null, source: string},
     *     grants: array{consuming_nodes: array<int, string>, serving_nodes: array<int, string>}
     * }
     */
    private function nodePayload(Node $node): array
    {
        return [
            'name' => $node->name,
            'role' => $node->role,
            'status' => $node->status,
            'environment' => $node->role === 'app' ? $node->environment : null,
            'platform' => $node->platform ?? 'unknown',
            'addresses' => [
                'wireguard' => $node->wireguard_address ?? $node->host,
            ],
            'agent_ide' => [
                'adapter' => null,
                'source' => 'default',
            ],
            'grants' => [
                'consuming_nodes' => [],
                'serving_nodes' => [],
            ],
        ];
    }

    /**
     * @param  array{
     *     name: string,
     *     role: string,
     *     status: string,
     *     environment: string|null,
     *     platform: string,
     *     addresses: array{wireguard: string},
     *     agent_ide: array{adapter: null, source: string},
     *     grants: array{consuming_nodes: array<int, string>, serving_nodes: array<int, string>}
     * }  $node
     */
    private function renderHuman(array $node): void
    {
        $this->line("Node: {$node['name']}");
        $this->line("Role: {$node['role']}");

        if ($node['environment'] !== null) {
            $this->line("Environment: {$node['environment']}");
        }

        $this->line("Platform: {$node['platform']}");
        $this->line("WireGuard: {$node['addresses']['wireguard']}");

        $consuming = $node['grants']['consuming_nodes'];
        $serving = $node['grants']['serving_nodes'];

        $this->line('Grants:');
        $consumingStr = $consuming !== [] ? implode(', ', $consuming) : '(none)';
        $servingStr = $serving !== [] ? implode(', ', $serving) : '(none)';
        $this->line("  Consuming: {$consumingStr}");
        $this->line("  Serving: {$servingStr}");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function jsonSuccess(array $data): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => empty($meta) ? (object) [] : $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }
}
