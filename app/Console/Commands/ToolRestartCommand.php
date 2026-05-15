<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\HandlesPromptCancellation;
use App\Console\Commands\Concerns\RunsToolActionProgress;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Http\Gateway\Requests\Tools\RestartToolRequest;
use App\Http\Gateway\Responses\Gateway\GatewayIdentityResponse;
use App\Http\Gateway\Responses\Tools\ToolShowResponse;
use App\Http\Gateway\ToolActionGatewayStreamClient;
use App\Models\App;
use App\Models\LocalNodeDefault;
use App\Models\Node;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolLifecycleManager;
use App\Services\Tools\ToolRegistryFailure;
use App\Support\Cli\RemoteProgressRenderer;
use App\Support\Cli\RemoteProgressReporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('tool:restart
    {tool? : Tool catalog name to restart}
    {--app= : Resolve target by app selector}
    {--node= : Resolve target by node}
    {--json : Output JSON}')]
#[Description('Restart a managed tool')]
class ToolRestartCommand extends Command
{
    use HandlesPromptCancellation;
    use RunsToolActionProgress;

    public function handle(
        ToolLifecycleManager $lifecycle,
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
                message: "Invalid tool name '{$tool}'.",
                meta: [
                    'field' => 'tool',
                    'value' => $tool,
                    'reason' => 'unknown_tool',
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
                ? $this->runRestartLocalToolActionProgress(
                    title: 'Restarting Tool',
                    doneFooter: 'Tool restarted',
                    failFooter: 'Tool restart failed',
                    operation: fn (): array|ToolRegistryFailure => $lifecycle->restart($tool, node: $node, app: $app),
                )
                : $this->runGatewayToolActionProgress(
                    stream: $stream,
                    action: 'restart',
                    tool: $tool,
                    payload: [
                        'app' => $app,
                        'node' => $node,
                    ],
                    unavailableMessage: 'Gateway connection is required to restart tools.',
                    defaultFooter: 'Tool restart failed',
                );

            if (! $progressResult['ok']) {
                return $this->failCommand(
                    code: $progressResult['code'],
                    message: $progressResult['message'],
                    meta: $progressResult['meta'],
                );
            }

            $result = $this->unwrapToolActionData($progressResult['data']);
            $this->line("Restarted {$result['name']} on {$result['node']}.");

            return self::SUCCESS;
        }

        $result = $this->isGatewayCaller()
            ? $lifecycle->restart($tool, node: $node, app: $app)
            : $this->restartViaGateway($tool, node: $node, app: $app);

        if ($result instanceof GatewayApiException) {
            return $this->failCommand(
                code: $result->errorCode() ?? 'gateway_unavailable',
                message: $result->getMessage() !== ''
                    ? $result->getMessage()
                    : 'Gateway connection is required to restart tools.',
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
    private function restartViaGateway(string $tool, ?string $node, ?string $app): array|GatewayApiException
    {
        try {
            $dto = app(GatewayConnector::class)
                ->send(new RestartToolRequest(tool: $tool, app: $app, node: $node))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        } catch (Throwable) {
            return new GatewayApiException(
                message: 'Gateway connection is required to restart tools.',
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
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, code: string, message: string, meta: array<string, mixed>, data: array<string, mixed>}
     */
    private function runRestartLocalToolActionProgress(
        string $title,
        string $doneFooter,
        string $failFooter,
        callable $operation,
    ): array {
        $reporter = new RemoteProgressReporter(new RemoteProgressRenderer($this->output));
        $reporter->tree($title, $this->toolRestartProgressSteps());

        try {
            $result = $this->runRestartProgressOperation($reporter, $operation);
        } catch (Throwable $exception) {
            $reporter->finish($failFooter, false);

            return [
                'ok' => false,
                'code' => 'gateway_unavailable',
                'message' => $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Gateway connection is required to manage tools.',
                'meta' => [],
                'data' => [],
            ];
        }

        if ($result instanceof GatewayApiException) {
            $reporter->finish($failFooter, false);

            return [
                'ok' => false,
                'code' => $result->errorCode() ?? 'gateway_unavailable',
                'message' => $result->getMessage(),
                'meta' => $result->errorMeta(),
                'data' => $result->errorData(),
            ];
        }

        if ($result instanceof ToolRegistryFailure) {
            $reporter->finish($failFooter, false);

            return [
                'ok' => false,
                'code' => $result->code,
                'message' => $result->message,
                'meta' => $result->meta,
                'data' => [],
            ];
        }

        $reporter->finish($doneFooter, true);

        return [
            'ok' => true,
            'data' => $result,
        ];
    }

    /**
     * @return list<array{key: string, label: string, doneLabel: string}>
     */
    private function toolRestartProgressSteps(): array
    {
        return [
            [
                'key' => 'resolve-target',
                'label' => 'Resolve target',
                'doneLabel' => 'Resolved target',
            ],
            [
                'key' => 'read-intent',
                'label' => 'Read gateway tool configuration',
                'doneLabel' => 'Read gateway tool configuration',
            ],
            [
                'key' => 'run-action',
                'label' => 'Run command action',
                'doneLabel' => 'Ran command action',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|ToolRegistryFailure|GatewayApiException
     */
    private function runRestartProgressOperation(RemoteProgressReporter $reporter, callable $operation): array|ToolRegistryFailure|GatewayApiException
    {
        $reporter->stepStart('resolve-target');
        $reporter->stepDone('resolve-target', 'target resolved');

        $reporter->stepStart('read-intent');
        $reporter->stepDone('read-intent', 'configuration read');

        $reporter->stepStart('run-action');
        $result = $operation();

        if ($result instanceof ToolRegistryFailure) {
            $reporter->stepFail('run-action', $result->message);

            return $result;
        }

        if ($result instanceof GatewayApiException) {
            $reporter->stepFail('run-action', $result->getMessage());

            return $result;
        }

        if (! is_array($result)) {
            $reporter->stepFail('run-action', 'Tool action returned an invalid response.');

            return ToolRegistryFailure::validation(
                'tool',
                '',
                'Tool action returned an invalid response.',
            );
        }

        $reporter->stepDone('run-action', 'command action completed');

        return $result;
    }

    /**
     * @return array{0: ?string, 1: ?string}|ToolRegistryFailure|GatewayApiException
     */
    private function resolveTargetOptions(?string $node, ?string $app): array|ToolRegistryFailure|GatewayApiException
    {
        if ($app !== null) {
            $appNode = $this->resolveAppNode($app);

            if (! $appNode instanceof Node) {
                return ToolRegistryFailure::validation(
                    'app',
                    $app,
                    "Invalid value for --app: '{$app}'. Expected a visible app name, domain, or app.node-tld selector.",
                );
            }

            if ($node !== null && $node !== $appNode->name) {
                return ToolRegistryFailure::validation(
                    'app',
                    $app,
                    "Invalid value for --app: '{$app}'. App is not owned by the selected node.",
                    [
                        'node' => $node,
                        'resolved_node' => $appNode->name,
                        'reason' => 'target_mismatch',
                    ],
                );
            }

            return [$appNode->name, null];
        }

        if ($node !== null) {
            return [$node, null];
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

    private function resolveAppNode(string $app): ?Node
    {
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

        $this->line('Repair convergence with orbit doctor --fix --family=tool --restore.');

        if ($tool !== null) {
            $command = "orbit tool:restart {$tool}";

            if ($node !== null && $node !== '') {
                $command .= " --node={$node}";
            }

            $this->line("Retry with {$command}.");
        }
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }
}
