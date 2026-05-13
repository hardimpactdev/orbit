<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsToolActionProgress;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Tools\UpdateToolRequest;
use App\Http\Gateway\Requests\Tools\UpdateToolsBulkRequest;
use App\Http\Gateway\Responses\Tools\ToolUpdateBulkResponse;
use App\Http\Gateway\Responses\Tools\ToolUpdateResponse;
use App\Http\Gateway\ToolActionGatewayStreamClient;
use App\Services\Tools\ToolRegistryFailure;
use App\Services\Tools\ToolUpdater;
use App\Support\Tools\ToolActionProgressRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tool:update
    {tool? : Tool catalog name to update}
    {--app= : Resolve target by app selector}
    {--node= : Resolve target by node}
    {--expected-version= : Expected version constraint}
    {--json : Output JSON}')]
#[Description('Update a managed tool')]
class ToolUpdateCommand extends Command
{
    use RunsToolActionProgress;

    public function handle(
        ToolUpdater $updater,
        ToolActionProgressRunner $progress,
        ToolActionGatewayStreamClient $stream,
    ): int {
        $tool = $this->stringArgument('tool');
        $node = $this->stringOption('node');
        $app = $this->stringOption('app');
        $version = $this->stringOption('expected-version');

        if ($tool !== null) {
            return $this->handleSingleTool($updater, $progress, $stream, $tool, $node, $app, $version);
        }

        return $this->handleBulkUpdate($updater, $progress, $stream, $node, $app);
    }

    private function handleSingleTool(
        ToolUpdater $updater,
        ToolActionProgressRunner $progress,
        ToolActionGatewayStreamClient $stream,
        string $tool,
        ?string $node,
        ?string $app,
        ?string $version,
    ): int {
        if (! $this->wantsJson()) {
            $progressResult = $this->isGatewayCaller()
                ? $this->runLocalToolActionProgress(
                    progress: $progress,
                    title: 'Updating Tool',
                    doneFooter: 'Tool updated',
                    failFooter: 'Tool update failed',
                    operation: fn (): array|ToolRegistryFailure => $updater->update($tool, node: $node, app: $app, expectedVersion: $version),
                )
                : $this->runGatewayToolActionProgress(
                    stream: $stream,
                    action: 'update',
                    tool: $tool,
                    payload: [
                        'app' => $app,
                        'node' => $node,
                        'version' => $version,
                    ],
                    unavailableMessage: 'Gateway connection is required to update tools.',
                    defaultFooter: 'Tool update failed',
                );

            if (! $progressResult['ok']) {
                return $this->failCommand(
                    code: $progressResult['code'],
                    message: $progressResult['message'],
                    meta: $progressResult['meta'],
                );
            }

            $result = $this->unwrapToolActionData($progressResult['data']);
            $this->line("Updated {$result['name']} on {$result['node']}.");

            return self::SUCCESS;
        }

        $result = $this->isGatewayCaller()
            ? $updater->update($tool, node: $node, app: $app, expectedVersion: $version)
            : $this->updateViaGateway($tool, node: $node, app: $app, version: $version);

        if ($result instanceof GatewayApiException) {
            return $this->failCommand(
                code: $result->errorCode() ?? 'gateway_unavailable',
                message: $result->getMessage() !== ''
                    ? $result->getMessage()
                    : 'Gateway connection is required to update tools.',
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

    private function handleBulkUpdate(
        ToolUpdater $updater,
        ToolActionProgressRunner $progress,
        ToolActionGatewayStreamClient $stream,
        ?string $node,
        ?string $app,
    ): int {
        if (! $this->wantsJson()) {
            $progressResult = $this->isGatewayCaller()
                ? $this->runLocalToolActionProgress(
                    progress: $progress,
                    title: 'Updating Tool',
                    doneFooter: 'Tool update completed',
                    failFooter: 'Tool update failed',
                    operation: fn (): array => $updater->updateAll(node: $node, app: $app),
                    successful: fn (array $result): bool => $result['failed'] === [],
                )
                : $this->runGatewayToolActionProgress(
                    stream: $stream,
                    action: 'update-all',
                    tool: null,
                    payload: [
                        'app' => $app,
                        'node' => $node,
                    ],
                    unavailableMessage: 'Gateway connection is required to update tools.',
                    defaultFooter: 'Tool update failed',
                );

            if (! $progressResult['ok'] && $progressResult['data'] === []) {
                return $this->failCommand(
                    code: $progressResult['code'],
                    message: $progressResult['message'],
                    meta: $progressResult['meta'],
                );
            }

            return $this->renderBulkUpdateResult($progressResult['data']);
        }

        $result = $this->isGatewayCaller()
            ? $updater->updateAll(node: $node, app: $app)
            : $this->updateBulkViaGateway(node: $node, app: $app);

        if ($result instanceof GatewayApiException) {
            return $this->failCommand(
                code: $result->errorCode() ?? 'gateway_unavailable',
                message: $result->getMessage() !== ''
                    ? $result->getMessage()
                    : 'Gateway connection is required to update tools.',
                meta: $result->errorMeta(),
            );
        }

        $exitCode = $result['failed'] !== [] ? self::FAILURE : self::SUCCESS;

        return $this->jsonSuccess($result, $exitCode);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderBulkUpdateResult(array $result): int
    {
        $updated = is_array($result['updated'] ?? null) ? $result['updated'] : [];
        $skipped = is_array($result['skipped'] ?? null) ? $result['skipped'] : [];
        $failed = is_array($result['failed'] ?? null) ? $result['failed'] : [];

        foreach ($updated as $item) {
            if (! is_array($item)) {
                continue;
            }

            $this->line("Updated {$item['tool']} on {$item['node']}.");
        }

        foreach ($skipped as $item) {
            if (! is_array($item)) {
                continue;
            }

            $this->line("Skipped {$item['tool']} on {$item['node']}: {$item['reason']}");
        }

        foreach ($failed as $item) {
            if (! is_array($item)) {
                continue;
            }

            $this->error("Failed {$item['tool']} on {$item['node']}: {$item['error']}");
        }

        return $failed !== [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|GatewayApiException
     */
    private function updateViaGateway(string $tool, ?string $node, ?string $app, ?string $version): array|GatewayApiException
    {
        try {
            $dto = app(GatewayConnector::class)
                ->send(new UpdateToolRequest(tool: $tool, app: $app, node: $node, version: $version))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        } catch (Throwable) {
            return new GatewayApiException(
                message: 'Gateway connection is required to update tools.',
                errorCode: 'gateway_unavailable',
                errorMeta: [],
            );
        }

        /** @var ToolUpdateResponse $dto */
        return $dto->tool;
    }

    /**
     * @return array{updated: list<array<string, mixed>>, skipped: list<array<string, mixed>>, failed: list<array<string, mixed>>}|GatewayApiException
     */
    private function updateBulkViaGateway(?string $node, ?string $app): array|GatewayApiException
    {
        try {
            $dto = app(GatewayConnector::class)
                ->send(new UpdateToolsBulkRequest(app: $app, node: $node))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        } catch (Throwable) {
            return new GatewayApiException(
                message: 'Gateway connection is required to update tools.',
                errorCode: 'gateway_unavailable',
                errorMeta: [],
            );
        }

        /** @var ToolUpdateBulkResponse $dto */
        return [
            'updated' => $dto->updated,
            'skipped' => $dto->skipped,
            'failed' => $dto->failed,
        ];
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
    private function jsonSuccess(array $data, int $exitCode = self::SUCCESS): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => (object) [],
            ],
        ], JSON_THROW_ON_ERROR));

        return $exitCode;
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
