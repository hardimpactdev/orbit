<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Tools\InstallToolRequest;
use App\Http\Gateway\Responses\Tools\ToolInstallResponse;
use App\Models\Node;
use App\Services\Tools\ToolInstaller;
use App\Services\Tools\ToolRegistryFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tool:install
    {tool : Tool catalog name to install}
    {--app= : Resolve target by app selector}
    {--node= : Resolve target by node}
    {--status=installed : Desired state after install (installed|running)}
    {--expected-version= : Expected version constraint}
    {--json : Output JSON}')]
#[Description('Install a managed tool')]
class ToolInstallCommand extends Command
{
    public function handle(ToolInstaller $installer): int
    {
        $tool = (string) $this->argument('tool');
        $node = $this->stringOption('node');
        $app = $this->stringOption('app');
        $status = (string) $this->option('status');
        $version = $this->stringOption('expected-version');

        if (! in_array($status, ['installed', 'running'], true)) {
            return $this->failCommand(
                code: 'invalid_status',
                message: "Invalid --status value '{$status}'. Valid values: installed, running.",
                meta: ['field' => 'status'],
            );
        }

        $result = $this->isGatewayCaller()
            ? $installer->install(
                tool: $tool,
                node: $node,
                app: $app,
                expectedVersion: $version,
                expectedState: $status,
            )
            : $this->installViaGateway($tool, node: $node, app: $app, status: $status, version: $version);

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

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['tool' => $result]);
        }

        $this->line("Installed {$result['name']} on {$result['node']} ({$result['state']}).");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|GatewayApiException
     */
    private function installViaGateway(
        string $tool,
        ?string $node,
        ?string $app,
        string $status,
        ?string $version,
    ): array|GatewayApiException {
        try {
            $dto = app(GatewayConnector::class)
                ->send(new InstallToolRequest(
                    tool: $tool,
                    app: $app,
                    node: $node,
                    status: $status,
                    version: $version,
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
