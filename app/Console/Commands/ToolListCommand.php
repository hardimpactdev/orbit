<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Tools\ListToolsRequest;
use App\Http\Gateway\Responses\Tools\ToolListResponse;
use App\Models\NodeTool;
use App\Services\Tools\ToolPayloadMapper;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\ToolRegistryFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\table;

#[Signature('tool:list
    {--app= : Filter by app selector}
    {--node= : Filter by owning node}
    {--json : Output as JSON}')]
#[Description('List tool state tracked by the gateway registry')]
class ToolListCommand extends Command
{
    public function handle(ToolRegistry $registry, ToolPayloadMapper $payloads): int
    {
        $node = $this->stringOption('node');
        $app = $this->stringOption('app');

        $tools = $this->isGatewayCaller()
            ? $this->fetchLocalTools($registry, $payloads, $node, $app)
            : $this->fetchGatewayTools($node, $app);

        if ($tools instanceof GatewayApiException) {
            return $this->failCommand(
                code: $tools->errorCode() ?? 'gateway_unavailable',
                message: $tools->getMessage() !== ''
                    ? $tools->getMessage()
                    : 'Gateway connection is required to list tools.',
                meta: $tools->errorMeta(),
            );
        }

        if ($tools instanceof ToolRegistryFailure) {
            return $this->failCommand(
                code: $tools->code,
                message: $tools->message,
                meta: $tools->meta,
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['tools' => $tools]);
        }

        $this->renderHuman($tools);

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>|ToolRegistryFailure
     */
    private function fetchLocalTools(ToolRegistry $registry, ToolPayloadMapper $payloads, ?string $node, ?string $app): array|ToolRegistryFailure
    {
        $failure = $registry->validateFilters(node: $node, app: $app);

        if ($failure instanceof ToolRegistryFailure) {
            return $failure;
        }

        return $registry->list(node: $node, app: $app)
            ->map(fn (NodeTool $tool): array => $payloads->toArray($tool))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>|GatewayApiException
     */
    private function fetchGatewayTools(?string $node, ?string $app): array|GatewayApiException
    {
        try {
            $dto = app(GatewayConnector::class)
                ->send(new ListToolsRequest(app: $app, node: $node))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        } catch (Throwable) {
            return new GatewayApiException(
                message: 'Gateway connection is required to list tools.',
                errorCode: 'gateway_unavailable',
                errorMeta: [],
            );
        }

        /** @var ToolListResponse $dto */
        return $dto->tools;
    }

    private function isGatewayCaller(): bool
    {
        return (bool) config('orbit.is_gateway', false);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     */
    private function renderHuman(array $tools): void
    {
        if ($tools === []) {
            $this->line('No tools found.');

            return;
        }

        $currentNode = (string) ($tools[0]['node'] ?? 'without node');
        $rows = [];

        foreach ($tools as $tool) {
            $node = (string) ($tool['node'] ?? 'without node');

            if ($node !== $currentNode) {
                $this->renderNodeTable($currentNode, $rows);
                $this->newLine();
                $rows = [];
            }

            $currentNode = $node;
            $rows[] = [
                $tool['name'],
                $tool['expected_state'],
                $tool['observed_state'] ?? '(not observed)',
                $tool['version'] ?? '(unknown)',
                ($tool['managed'] ?? false) ? 'yes' : 'no',
            ];
        }

        $this->renderNodeTable($currentNode, $rows);
    }

    /**
     * @param  list<array<int, mixed>>  $rows
     */
    private function renderNodeTable(string $node, array $rows): void
    {
        $this->line("Node: {$node}");
        table(['Tool', 'Expected', 'Observed', 'Version', 'Managed'], $rows);
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
