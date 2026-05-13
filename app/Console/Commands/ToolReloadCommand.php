<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsToolActionProgress;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Tools\ReloadToolRequest;
use App\Http\Gateway\Responses\Tools\ToolShowResponse;
use App\Http\Gateway\ToolActionGatewayStreamClient;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolLifecycleManager;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\ToolRegistryFailure;
use App\Support\Tools\ToolActionProgressRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\select;

#[Signature('tool:reload
    {tool? : Tool catalog name to reload}
    {--app= : Resolve target by app selector}
    {--node= : Resolve target by node}
    {--json : Output JSON}')]
#[Description('Reload a managed tool')]
class ToolReloadCommand extends Command
{
    use RunsToolActionProgress;

    public function handle(
        ToolLifecycleManager $lifecycle,
        ToolRegistry $registry,
        ToolCatalog $catalog,
        ToolActionProgressRunner $progress,
        ToolActionGatewayStreamClient $stream,
    ): int {
        $tool = $this->stringArgument('tool');
        $node = $this->stringOption('node');
        $app = $this->stringOption('app');

        if ($tool === null) {
            if (! $this->isInteractiveInput()) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'A tool is required in non-interactive mode.',
                    meta: ['field' => 'tool'],
                );
            }

            $selection = $this->promptForReloadableTool($registry, $catalog, node: $node, app: $app);

            if (is_int($selection)) {
                return $selection;
            }

            $tool = $selection['tool'];
            $node = $selection['node'];
        }

        if (! $this->wantsJson()) {
            $progressResult = $this->isGatewayCaller()
                ? $this->runLocalToolActionProgress(
                    progress: $progress,
                    title: 'Reloading Tool',
                    doneFooter: 'Tool reloaded',
                    failFooter: 'Tool reload failed',
                    operation: fn (): array|ToolRegistryFailure => $lifecycle->reload($tool, node: $node, app: $app),
                )
                : $this->runGatewayToolActionProgress(
                    stream: $stream,
                    action: 'reload',
                    tool: $tool,
                    payload: [
                        'app' => $app,
                        'node' => $node,
                    ],
                    unavailableMessage: 'Gateway connection is required to reload tools.',
                    defaultFooter: 'Tool reload failed',
                );

            if (! $progressResult['ok']) {
                return $this->failCommand(
                    code: $progressResult['code'],
                    message: $progressResult['message'],
                    meta: $progressResult['meta'],
                );
            }

            $result = $this->unwrapToolActionData($progressResult['data']);
            $this->line("Reloaded {$result['name']} on {$result['node']}.");

            return self::SUCCESS;
        }

        $result = $this->isGatewayCaller()
            ? $lifecycle->reload($tool, node: $node, app: $app)
            : $this->reloadViaGateway($tool, node: $node, app: $app);

        if ($result instanceof GatewayApiException) {
            return $this->failCommand(
                code: $result->errorCode() ?? 'gateway_unavailable',
                message: $result->getMessage() !== ''
                    ? $result->getMessage()
                    : 'Gateway connection is required to reload tools.',
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

        return $this->jsonSuccess(['tool' => $result]);
    }

    /**
     * @return array{tool: string, node: string}|int
     */
    private function promptForReloadableTool(ToolRegistry $registry, ToolCatalog $catalog, ?string $node, ?string $app): array|int
    {
        $validation = $registry->validateFilters(node: $node, app: $app);

        if ($validation instanceof ToolRegistryFailure) {
            return $this->failCommand(
                code: $validation->code,
                message: $validation->message,
                meta: $validation->meta,
            );
        }

        $choices = [];

        foreach ($registry->list(node: $node, app: $app) as $tool) {
            if (! $tool instanceof NodeTool || ! $catalog->hasRepairCommand($tool->name, 'lifecycle_reloaded')) {
                continue;
            }

            $tool->loadMissing('node');

            if (! $tool->node instanceof Node) {
                continue;
            }

            $choices["{$tool->name} on {$tool->node->name}"] = [
                'tool' => $tool->name,
                'node' => $tool->node->name,
            ];
        }

        if ($choices === []) {
            return $this->failCommand(
                code: 'tool.unsupported_action',
                message: 'No reload-capable tools are visible for the selected target.',
                meta: ['action' => 'reload'],
            );
        }

        $answer = (string) select('Which tool should be reloaded?', array_keys($choices));

        return $choices[$answer] ?? $this->failCommand(
            code: 'validation_failed',
            message: 'Selected tool is invalid.',
            meta: ['field' => 'tool'],
        );
    }

    /**
     * @return array<string, mixed>|GatewayApiException
     */
    private function reloadViaGateway(string $tool, ?string $node, ?string $app): array|GatewayApiException
    {
        try {
            $dto = app(GatewayConnector::class)
                ->send(new ReloadToolRequest(tool: $tool, app: $app, node: $node))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        } catch (Throwable) {
            return new GatewayApiException(
                message: 'Gateway connection is required to reload tools.',
                errorCode: 'gateway_unavailable',
                errorMeta: [],
            );
        }

        /** @var ToolShowResponse $dto */
        return $dto->tool;
    }

    private function isGatewayCaller(): bool
    {
        return (bool) config('orbit.is_gateway', false);
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }
}
