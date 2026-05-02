<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Node;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('node:show
    {name? : Node name to inspect}
    {--json : Output JSON}')]
#[Description('Show node details from the gateway registry')]
class NodeShowCommand extends Command
{
    public function handle(): int
    {
        $name = $this->argument('name');

        if (! is_string($name) || $name === '') {
            $name = $this->defaultNodeName();
        }

        if ($name === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Node name is required.',
                meta: ['field' => 'name'],
            );
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

    private function defaultNodeName(): ?string
    {
        $name = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->value('name');

        return is_string($name) && $name !== '' ? $name : null;
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
