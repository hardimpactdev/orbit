<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\HandlesPromptCancellation;
use App\Console\Commands\Concerns\RunsToolActionProgress;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Tools\RemoveToolRequest;
use App\Http\Gateway\Responses\Tools\ToolShowResponse;
use App\Http\Gateway\ToolActionGatewayStreamClient;
use App\Models\LocalNodeDefault;
use App\Models\Node;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\ToolRegistryFailure;
use App\Services\Tools\ToolRemover;
use App\Support\Tools\ToolActionProgressRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tool:remove
    {tool? : Tool catalog name to remove}
    {--app= : Resolve target by app selector}
    {--node= : Resolve target by node}
    {--force : Confirm destructive removal}
    {--json : Output JSON}')]
#[Description('Remove a managed tool')]
class ToolRemoveCommand extends Command
{
    use HandlesPromptCancellation;
    use RunsToolActionProgress;

    public function handle(
        ToolRemover $remover,
        ToolActionProgressRunner $progress,
        ToolActionGatewayStreamClient $stream,
        ToolCatalog $catalog,
        ToolRegistry $registry,
    ): int {
        $tool = $this->stringArgument('tool');

        if ($tool === null) {
            if (! $this->isInteractiveInput()) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'A tool name is required.',
                    meta: ['field' => 'tool'],
                );
            }

            try {
                $names = $catalog->names();
                $tool = (string) $this->promptSearch(
                    label: 'Tool name',
                    options: fn (string $value): array => array_values(array_filter($names, fn (string $n): bool => $value === '' || str_contains($n, $value))),
                );
            } catch (PromptAborted) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Operation cancelled.',
                    meta: [],
                );
            }
        }

        $node = $this->stringOption('node');
        $app = $this->stringOption('app');

        $target = $this->resolveTargetOptions($node, $app);

        if ($target instanceof ToolRegistryFailure) {
            return $this->failCommand(
                code: $target->code,
                message: $target->message,
                meta: $this->commandFailureMeta($target),
            );
        }

        [$node, $app] = $target;

        $targetName = $node ?? (string) $app;

        if ($this->isGatewayCaller()) {
            if (! $catalog->supports($tool)) {
                $failure = ToolRegistryFailure::unsupportedAction($tool, 'remove');

                return $this->failCommand($failure->code, $failure->message, $failure->meta);
            }

            $model = $registry->show(tool: $tool, node: $node, app: $app);

            if ($model instanceof ToolRegistryFailure) {
                return $this->failCommand($model->code, $model->message, $model->meta);
            }

            if (! $catalog->hasCapability($tool, 'remove')) {
                $failure = ToolRegistryFailure::unsupportedAction($tool, 'remove');

                return $this->failCommand($failure->code, $failure->message, $failure->meta);
            }

            $model->loadMissing('node');
            $targetName = $model->node instanceof Node ? $model->node->name : $targetName;
        }

        if (! $this->option('force') && ! $this->option('json')) {
            if (! $this->isInteractiveInput()) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Use --force or --json to remove this tool.',
                    meta: ['field' => 'force', 'reason' => 'destructive_consent_required'],
                );
            }

            try {
                $confirmed = $this->promptConfirm("Remove tool '{$tool}' from '{$targetName}'?", default: false);
            } catch (PromptAborted) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Operation cancelled.',
                    meta: ['field' => 'force', 'reason' => 'cancelled'],
                );
            }

            if (! $confirmed) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Operation cancelled.',
                    meta: ['field' => 'force', 'reason' => 'cancelled'],
                );
            }
        }

        $destructiveConsentSource = $this->destructiveConsentSource();

        if (! $this->wantsJson()) {
            $progressResult = $this->isGatewayCaller()
                ? $this->runLocalToolActionProgress(
                    progress: $progress,
                    title: 'Removing Tool',
                    doneFooter: 'Tool removed',
                    failFooter: 'Tool remove failed',
                    operation: fn (): array|ToolRegistryFailure => $remover->remove($tool, node: $node, app: $app),
                )
                : $this->runGatewayToolActionProgress(
                    stream: $stream,
                    action: 'remove',
                    tool: $tool,
                    payload: [
                        'app' => $app,
                        'node' => $node,
                        'destructive_consent_source' => $destructiveConsentSource,
                    ],
                    unavailableMessage: 'Gateway connection is required to remove tools.',
                    defaultFooter: 'Tool remove failed',
                );

            if (! $progressResult['ok']) {
                return $this->failCommand(
                    code: $progressResult['code'],
                    message: $progressResult['message'],
                    meta: $progressResult['meta'],
                );
            }

            $result = $this->unwrapToolActionData($progressResult['data']);
            $this->line("Removed {$result['name']} from {$result['node']}.");

            return self::SUCCESS;
        }

        $result = $this->isGatewayCaller()
            ? $remover->remove($tool, node: $node, app: $app)
            : $this->removeViaGateway(
                tool: $tool,
                node: $node,
                app: $app,
                destructiveConsentSource: $destructiveConsentSource,
            );

        if ($result instanceof GatewayApiException) {
            return $this->failCommand(
                code: $result->errorCode() ?? 'gateway_unavailable',
                message: $result->getMessage() !== ''
                    ? $result->getMessage()
                    : 'Gateway connection is required to remove tools.',
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
     * @return array<string, mixed>|GatewayApiException
     */
    private function removeViaGateway(string $tool, ?string $node, ?string $app, string $destructiveConsentSource): array|GatewayApiException
    {
        try {
            $dto = app(GatewayConnector::class)
                ->send(new RemoveToolRequest(
                    tool: $tool,
                    app: $app,
                    node: $node,
                    destructiveConsentSource: $destructiveConsentSource,
                ))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        } catch (Throwable) {
            return new GatewayApiException(
                message: 'Gateway connection is required to remove tools.',
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

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
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
     * @return array{0: ?string, 1: ?string}|ToolRegistryFailure
     */
    private function resolveTargetOptions(?string $node, ?string $app): array|ToolRegistryFailure
    {
        if ($node !== null || $app !== null) {
            return [$node, $app];
        }

        $defaultNode = LocalNodeDefault::query()->value('default_node_name');

        if (is_string($defaultNode) && trim($defaultNode) !== '') {
            return [trim($defaultNode), null];
        }

        if ($this->isInteractiveInput()) {
            $nodes = Node::query()
                ->where('role', 'app')
                ->where('status', 'active')
                ->orderBy('name')
                ->pluck('name', 'name')
                ->all();

            if ($nodes !== []) {
                try {
                    return [(string) $this->promptSelect('Target node', $nodes), null];
                } catch (PromptAborted) {
                    return ToolRegistryFailure::validation('target', '', 'Operation cancelled.');
                }
            }
        }

        return ToolRegistryFailure::validation(
            'target',
            '',
            'A node or app target is required. Provide --node, --app, configure node:default, or select a target interactively.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function commandFailureMeta(ToolRegistryFailure $failure): array
    {
        if (($failure->meta['field'] ?? null) === 'target') {
            return ['fields' => ['target']];
        }

        return $failure->meta;
    }

    private function destructiveConsentSource(): string
    {
        if ($this->option('force')) {
            return 'force';
        }

        if ($this->option('json')) {
            return 'json';
        }

        return 'interactive_confirm';
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
