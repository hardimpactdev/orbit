<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\HandlesPromptCancellation;
use App\Console\Commands\Concerns\RunsToolActionProgress;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Tools\InstallToolRequest;
use App\Http\Gateway\Responses\Tools\ToolInstallResponse;
use App\Http\Gateway\ToolActionGatewayStreamClient;
use App\Models\LocalNodeDefault;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolInstaller;
use App\Services\Tools\ToolRegistryFailure;
use App\Support\Tools\ToolActionProgressRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tool:install
    {tool? : Tool catalog name to install}
    {--app= : Resolve target by app selector}
    {--node= : Resolve target by node}
    {--status=installed : Desired state after install (installed|running)}
    {--json : Output JSON}')]
#[Description('Install a managed tool')]
class ToolInstallCommand extends Command
{
    use HandlesPromptCancellation;
    use RunsToolActionProgress;

    public function handle(
        ToolInstaller $installer,
        ToolActionProgressRunner $progress,
        ToolActionGatewayStreamClient $stream,
        ToolCatalog $catalog,
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
        $status = (string) $this->option('status');

        if (! in_array($status, ['installed', 'running'], true)) {
            return $this->failCommand(
                code: 'validation_failed',
                message: "Invalid --status value '{$status}'. Valid values: installed, running.",
                meta: [
                    'field' => 'status',
                    'value' => $status,
                    'reason' => 'unsupported_value',
                ],
            );
        }

        $target = $this->resolveTargetOptions($node, $app);

        if ($target instanceof ToolRegistryFailure) {
            return $this->failCommand(
                code: $target->code,
                message: $target->message,
                meta: $this->commandFailureMeta($target),
            );
        }

        [$node, $app] = $target;

        if (! $this->wantsJson()) {
            $progressResult = $this->isGatewayCaller()
                ? $this->runLocalToolActionProgress(
                    progress: $progress,
                    title: 'Installing Tool',
                    doneFooter: 'Tool installed',
                    failFooter: 'Tool install failed',
                    operation: fn (): array|ToolRegistryFailure => $installer->install(
                        tool: $tool,
                        node: $node,
                        app: $app,
                        expectedState: $status,
                    ),
                )
                : $this->runGatewayToolActionProgress(
                    stream: $stream,
                    action: 'install',
                    tool: $tool,
                    payload: [
                        'app' => $app,
                        'node' => $node,
                        'status' => $status,
                        'config' => [],
                    ],
                    unavailableMessage: 'Gateway connection is required to install tools.',
                    defaultFooter: 'Tool install failed',
                );

            if (! $progressResult['ok']) {
                return $this->failCommand(
                    code: $progressResult['code'],
                    message: $progressResult['message'],
                    meta: $progressResult['meta'],
                );
            }

            $result = $this->unwrapToolActionData($progressResult['data']);
            $this->line("Installed {$result['name']} on {$result['node']} ({$result['state']}).");

            return self::SUCCESS;
        }

        $result = $this->isGatewayCaller()
            ? $installer->install(
                tool: $tool,
                node: $node,
                app: $app,
                expectedState: $status,
            )
            : $this->installViaGateway($tool, node: $node, app: $app, status: $status);

        if ($result instanceof GatewayApiException) {
            return $this->failCommand(
                code: $result->errorCode() ?? 'gateway_unavailable',
                message: $result->getMessage() !== ''
                    ? $result->getMessage()
                    : 'Gateway connection is required to install tools.',
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
    private function installViaGateway(
        string $tool,
        ?string $node,
        ?string $app,
        string $status,
    ): array|GatewayApiException {
        try {
            $dto = app(GatewayConnector::class)
                ->send(new InstallToolRequest(
                    tool: $tool,
                    app: $app,
                    node: $node,
                    status: $status,
                    toolConfig: [],
                ))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        } catch (Throwable) {
            return new GatewayApiException(
                message: 'Gateway connection is required to install tools.',
                errorCode: 'gateway_unavailable',
                errorMeta: [],
            );
        }

        /** @var ToolInstallResponse $dto */
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
                ->whereIn('id', app(NodeRoleAssignments::class)->activeToolHostNodeIds())
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
