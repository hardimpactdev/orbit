<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\HandlesPromptCancellation;
use App\Console\Commands\Concerns\RunsToolActionProgress;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Http\Gateway\Requests\Tools\StopToolRequest;
use App\Http\Gateway\Responses\Gateway\GatewayIdentityResponse;
use App\Http\Gateway\Responses\Tools\ToolShowResponse;
use App\Http\Gateway\ToolActionGatewayStreamClient;
use App\Models\App;
use App\Models\LocalNodeDefault;
use App\Models\Node;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolLifecycleManager;
use App\Services\Tools\ToolRegistryFailure;
use App\Support\Tools\ToolActionProgressRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('tool:stop
    {tool? : Tool catalog name to stop}
    {--app= : Resolve target by app selector}
    {--node= : Resolve target by node}
    {--json : Output JSON}')]
#[Description('Stop a managed tool')]
class ToolStopCommand extends Command
{
    use HandlesPromptCancellation;
    use RunsToolActionProgress;

    public function handle(
        ToolLifecycleManager $lifecycle,
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

        if (! $catalog->supports($tool)) {
            return $this->failCommand(
                code: 'validation_failed',
                message: "Invalid value for tool: '{$tool}'. Expected a registered tool name.",
                meta: [
                    'field' => 'tool',
                    'value' => $tool,
                ],
            );
        }

        $target = $this->resolveTargetOptions($node, $app);

        if ($target instanceof GatewayApiException) {
            return $this->failCommand(
                code: $target->errorCode() ?? 'gateway_unavailable',
                message: $target->getMessage() !== ''
                    ? $target->getMessage()
                    : 'Gateway connection is required to resolve the caller identity.',
                meta: $target->errorMeta(),
            );
        }

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
                    title: 'Stopping Tool',
                    doneFooter: 'Tool stopped',
                    failFooter: 'Tool stop failed',
                    operation: fn (): array|ToolRegistryFailure => $lifecycle->stop($tool, node: $node, app: $app),
                )
                : $this->runGatewayToolActionProgress(
                    stream: $stream,
                    action: 'stop',
                    tool: $tool,
                    payload: [
                        'app' => $app,
                        'node' => $node,
                    ],
                    unavailableMessage: 'Gateway connection is required to stop tools.',
                    defaultFooter: 'Tool stop failed',
                );

            if (! $progressResult['ok']) {
                return $this->failCommand(
                    code: $progressResult['code'],
                    message: $progressResult['message'],
                    meta: $progressResult['meta'],
                );
            }

            $result = $this->unwrapToolActionData($progressResult['data']);
            $this->line("Stopped {$result['name']} on {$result['node']}.");

            return self::SUCCESS;
        }

        $result = $this->isGatewayCaller()
            ? $lifecycle->stop($tool, node: $node, app: $app)
            : $this->stopViaGateway($tool, node: $node, app: $app);

        if ($result instanceof GatewayApiException) {
            return $this->failCommand(
                code: $result->errorCode() ?? 'gateway_unavailable',
                message: $result->getMessage() !== ''
                    ? $result->getMessage()
                    : 'Gateway connection is required to stop tools.',
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
    private function stopViaGateway(string $tool, ?string $node, ?string $app): array|GatewayApiException
    {
        try {
            $dto = app(GatewayConnector::class)
                ->send(new StopToolRequest(tool: $tool, app: $app, node: $node))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        } catch (Throwable) {
            return new GatewayApiException(
                message: 'Gateway connection is required to stop tools.',
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

    /**
     * @return array{0: ?string, 1: ?string}|ToolRegistryFailure|GatewayApiException
     */
    private function resolveTargetOptions(?string $node, ?string $app): array|ToolRegistryFailure|GatewayApiException
    {
        if ($app !== null) {
            if (! $this->isGatewayCaller()) {
                return [$node, $app];
            }

            $appNode = $this->resolveAppNode($app);

            if (! $appNode instanceof Node) {
                return ToolRegistryFailure::validation('app', $app, "Invalid value for --app: '{$app}'. Expected a visible app name, domain, or app.node-tld selector.");
            }

            if ($node !== null) {
                $nodeFilter = $this->resolveNode($node);

                if (! $nodeFilter instanceof Node) {
                    return ToolRegistryFailure::validation('node', $node, "Invalid value for --node: '{$node}'. Expected a visible app node name.");
                }

                if ($nodeFilter->id !== $appNode->id) {
                    return ToolRegistryFailure::validation(
                        'app',
                        $app,
                        "Invalid value for --app: '{$app}'. App is not owned by the selected node.",
                        [
                            'node' => $nodeFilter->name,
                            'resolved_node' => $appNode->name,
                            'reason' => 'target_mismatch',
                        ],
                    );
                }
            }

            return [$appNode->name, null];
        }

        if ($node !== null) {
            if (! $this->isGatewayCaller()) {
                return [$node, null];
            }

            $nodeFilter = $this->resolveNode($node);

            if (! $nodeFilter instanceof Node) {
                return ToolRegistryFailure::validation('node', $node, "Invalid value for --node: '{$node}'. Expected a visible app node name.");
            }

            return [$nodeFilter->name, null];
        }

        $defaultNode = LocalNodeDefault::query()->value('default_node_name');

        if (is_string($defaultNode) && trim($defaultNode) !== '') {
            return [trim($defaultNode), null];
        }

        if (! $this->isGatewayCaller()) {
            try {
                return [$this->gatewayKnownSelfNodeName(), null];
            } catch (GatewayApiException $e) {
                return $e;
            }
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

    private function resolveNode(?string $node): ?Node
    {
        if ($node === null) {
            return null;
        }

        return Node::query()
            ->where('name', $node)
            ->where('role', 'app')
            ->where('status', 'active')
            ->first();
    }

    private function resolveAppNode(?string $app): ?Node
    {
        if ($app === null) {
            return null;
        }

        $model = App::query()
            ->with('node')
            ->where(function (Builder $query) use ($app): void {
                $query->where('name', $app)
                    ->orWhere('domain', $app);
            })
            ->first();

        if (! $model instanceof App && str_contains($app, '.')) {
            [$appName, $nodeTld] = explode('.', $app, 2);

            if ($appName !== '' && $nodeTld !== '') {
                $model = App::query()
                    ->with('node')
                    ->where('name', $appName)
                    ->whereHas('node', function (Builder $query) use ($nodeTld): void {
                        $query
                            ->where('role', 'app')
                            ->where('status', 'active')
                            ->where('tld', $nodeTld);
                    })
                    ->first();
            }
        }

        if (! $model instanceof App || ! $model->node instanceof Node) {
            return null;
        }

        if ($model->node->role !== 'app' || $model->node->status !== 'active') {
            return null;
        }

        return $model->node;
    }

    /**
     * @throws GatewayApiException
     */
    private function gatewayKnownSelfNodeName(): string
    {
        $request = new ShowGatewayIdentityRequest;

        try {
            $response = app(GatewayConnector::class)->send($request);
        } catch (GatewayApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new GatewayApiException(
                'Gateway connection is required to resolve the caller identity.',
                'gateway_unavailable',
                [],
                $e,
            );
        }

        if ($response->clientError() || $response->serverError()) {
            $exception = $request->getRequestException($response, null);

            if ($exception instanceof GatewayApiException) {
                throw $exception;
            }

            throw new GatewayApiException(
                "Gateway request failed with HTTP status {$response->status()}",
                'gateway_unavailable',
                ['endpoint' => '/api/me'],
            );
        }

        /** @var GatewayIdentityResponse $identity */
        $identity = $response->dto();
        $name = is_string($identity->self['name'] ?? null) ? trim($identity->self['name']) : '';

        if ($name === '') {
            throw new GatewayApiException(
                'Gateway identity response is missing node identity.',
                'gateway_unavailable',
                ['endpoint' => '/api/me'],
            );
        }

        return $name;
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

        if ($code === 'tool.remote_action_failed') {
            $this->renderRemoteActionFailureGuidance($meta);
        }

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function renderRemoteActionFailureGuidance(array $meta): void
    {
        $exitCode = $meta['exit_code'] ?? null;
        $stderr = $meta['stderr'] ?? null;
        $tool = is_string($meta['tool'] ?? null) ? $meta['tool'] : null;
        $node = is_string($meta['node'] ?? null) ? $meta['node'] : null;

        if (is_int($exitCode)) {
            $this->line("Exit code: {$exitCode}");
        }

        if (is_string($stderr) && trim($stderr) !== '') {
            $this->line('stderr: '.trim($stderr));
        }

        if ($tool !== null && app(ToolCatalog::class)->logCommand($tool, 50) !== null) {
            $command = "orbit tool:logs {$tool}";

            if ($node !== null && $node !== '') {
                $command .= " --node={$node}";
            }

            $this->line("Inspect logs with {$command}.");
        }

        $this->line('Repair convergence with orbit doctor --family=tool --restore.');
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }
}
