<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Tools\UpdateToolRequest;
use App\Http\Gateway\Requests\Tools\UpdateToolsBulkRequest;
use App\Http\Gateway\Responses\Tools\ToolUpdateBulkResponse;
use App\Http\Gateway\Responses\Tools\ToolUpdateResponse;
use App\Models\Node;
use App\Services\Tools\ToolRegistryFailure;
use App\Services\Tools\ToolUpdater;
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
    public function handle(ToolUpdater $updater): int
    {
        $tool = $this->stringArgument('tool');
        $node = $this->stringOption('node');
        $app = $this->stringOption('app');
        $version = $this->stringOption('expected-version');

        if ($tool !== null) {
            return $this->handleSingleTool($updater, $tool, $node, $app, $version);
        }

        return $this->handleBulkUpdate($updater, $node, $app);
    }

    private function handleSingleTool(ToolUpdater $updater, string $tool, ?string $node, ?string $app, ?string $version): int
    {
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

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['tool' => $result]);
        }

        $this->line("Updated {$result['name']} on {$result['node']}.");

        return self::SUCCESS;
    }

    private function handleBulkUpdate(ToolUpdater $updater, ?string $node, ?string $app): int
    {
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

        if ($this->wantsJson()) {
            $exitCode = $result['failed'] !== [] ? self::FAILURE : self::SUCCESS;

            return $this->jsonSuccess($result, $exitCode);
        }

        foreach ($result['updated'] as $item) {
            $this->line("Updated {$item['tool']} on {$item['node']}.");
        }

        foreach ($result['skipped'] as $item) {
            $this->line("Skipped {$item['tool']} on {$item['node']}: {$item['reason']}");
        }

        foreach ($result['failed'] as $item) {
            $this->error("Failed {$item['tool']} on {$item['node']}: {$item['error']}");
        }

        return $result['failed'] !== [] ? self::FAILURE : self::SUCCESS;
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
