<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\HandlesPromptCancellation;
use App\Console\Commands\Concerns\RunsToolActionProgress;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Tools\RestartToolRequest;
use App\Http\Gateway\Responses\Tools\ToolShowResponse;
use App\Http\Gateway\ToolActionGatewayStreamClient;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolLifecycleManager;
use App\Services\Tools\ToolRegistryFailure;
use App\Support\Tools\ToolActionProgressRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
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

        if (! $this->wantsJson()) {
            $progressResult = $this->isGatewayCaller()
                ? $this->runLocalToolActionProgress(
                    progress: $progress,
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
}
