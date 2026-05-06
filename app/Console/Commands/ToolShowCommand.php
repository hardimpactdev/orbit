<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Tools\ShowToolRequest;
use App\Http\Gateway\Responses\Tools\ToolShowResponse;
use App\Models\Node;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolPayloadMapper;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\ToolRegistryFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tool:show
    {tool : Tool catalog name to inspect}
    {--app= : Resolve target by app selector}
    {--node= : Resolve target by node}
    {--live : Request live gateway inspection}
    {--json : Output JSON}')]
#[Description('Show one tool tracked by the gateway registry')]
class ToolShowCommand extends Command
{
    public function handle(ToolCatalog $catalog, ToolRegistry $registry, ToolPayloadMapper $payloads): int
    {
        $tool = (string) $this->argument('tool');
        $node = $this->stringOption('node');
        $app = $this->stringOption('app');

        if (! $catalog->supports($tool)) {
            return $this->failCommand(
                code: 'tool.unsupported_action',
                message: "Tool '{$tool}' is not in the supported tool catalog.",
                meta: [
                    'tool' => $tool,
                    'supported' => $catalog->names(),
                ],
            );
        }

        $result = $this->isGatewayCaller()
            ? $this->fetchLocalTool($registry, $payloads, $tool, $node, $app)
            : $this->fetchGatewayTool($tool, $node, $app);

        if ($result instanceof GatewayApiException) {
            return $this->failCommand(
                code: $result->errorCode() ?? 'gateway_unavailable',
                message: $result->getMessage() !== ''
                    ? $result->getMessage()
                    : 'Gateway connection is required to show tool details.',
                meta: $result->errorMeta(),
            );
        }

        if ($result instanceof ToolRegistryFailure) {
            return $this->failCommand(
                code: $result->code,
                message: $result->message,
                meta: $result->meta,
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['tool' => $result]);
        }

        $this->renderHuman($result);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    private function fetchLocalTool(ToolRegistry $registry, ToolPayloadMapper $payloads, string $tool, ?string $node, ?string $app): array|ToolRegistryFailure
    {
        $model = $registry->show(tool: $tool, node: $node, app: $app);

        if ($model instanceof ToolRegistryFailure) {
            return $model;
        }

        return $payloads->toArray($model);
    }

    /**
     * @return array<string, mixed>|GatewayApiException
     */
    private function fetchGatewayTool(string $tool, ?string $node, ?string $app): array|GatewayApiException
    {
        try {
            $dto = app(GatewayConnector::class)
                ->send(new ShowToolRequest(
                    tool: $tool,
                    app: $app,
                    node: $node,
                    live: (bool) $this->option('live'),
                ))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        } catch (Throwable) {
            return new GatewayApiException(
                message: 'Gateway connection is required to show tool details.',
                errorCode: 'gateway_unavailable',
                errorMeta: [],
            );
        }

        /** @var ToolShowResponse $dto */
        return $dto->tool;
    }

    private function isGatewayCaller(): bool
    {
        return $this->callerRole() === 'gateway';
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

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $tool
     */
    private function renderHuman(array $tool): void
    {
        $this->line("Tool: {$tool['name']}");
        $this->line("Node: {$tool['node']}");
        $this->line("Expected: {$tool['expected_state']}");
        $this->line('Observed: '.($tool['observed_state'] ?? '(not observed)'));
        $this->line('Version: '.($tool['version'] ?? '(unknown)'));
        $this->line('Managed: '.(($tool['managed'] ?? false) ? 'yes' : 'no'));

        $this->line('');
        $this->line('Endpoints:');

        $endpoints = $tool['endpoints'] ?? [];

        if (! is_array($endpoints) || $endpoints === []) {
            $this->line('  (none)');

            return;
        }

        foreach ($endpoints as $endpoint) {
            if (! is_array($endpoint)) {
                continue;
            }

            $label = $endpoint['name'] ?? $endpoint['kind'] ?? 'endpoint';
            $host = $endpoint['host'] ?? null;
            $port = $endpoint['port'] ?? null;
            $this->line('  - '.$label.($host ? " {$host}" : '').($port ? ":{$port}" : ''));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function jsonSuccess(array $data): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => (object) [],
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
