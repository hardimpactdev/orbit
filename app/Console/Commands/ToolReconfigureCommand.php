<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsToolActionProgress;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Tools\ReconfigureToolRequest;
use App\Http\Gateway\Responses\Tools\ToolReconfigureResponse;
use App\Http\Gateway\ToolActionGatewayStreamClient;
use App\Models\Node;
use App\Services\Tools\ToolReconfigurer;
use App\Services\Tools\ToolRegistryFailure;
use App\Support\Tools\ToolActionProgressRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tool:reconfigure
    {tool : Tool catalog name to reconfigure}
    {--app= : Resolve target by app selector}
    {--node= : Resolve target by node}
    {--password= : Auth password (OpenCode Server)}
    {--json : Output JSON}')]
#[Description('Reconfigure a managed tool')]
class ToolReconfigureCommand extends Command
{
    use RunsToolActionProgress;

    public function handle(
        ToolReconfigurer $reconfigurer,
        ToolActionProgressRunner $progress,
        ToolActionGatewayStreamClient $stream,
    ): int {
        $tool = (string) $this->argument('tool');
        $node = $this->stringOption('node');
        $app = $this->stringOption('app');
        $password = $this->stringOption('password');

        if (! $this->wantsJson()) {
            $progressResult = $this->isGatewayCaller()
                ? $this->runLocalToolActionProgress(
                    progress: $progress,
                    title: 'Reconfiguring Tool',
                    doneFooter: 'Tool reconfigured',
                    failFooter: 'Tool reconfigure failed',
                    operation: fn (): array|ToolRegistryFailure => $reconfigurer->reconfigure($tool, node: $node, app: $app, password: $password),
                )
                : $this->runGatewayToolActionProgress(
                    stream: $stream,
                    action: 'reconfigure',
                    tool: $tool,
                    payload: [
                        'app' => $app,
                        'node' => $node,
                        'password' => $password,
                    ],
                    unavailableMessage: 'Gateway connection is required to reconfigure tools.',
                    defaultFooter: 'Tool reconfigure failed',
                );

            if (! $progressResult['ok']) {
                return $this->failCommand(
                    code: $progressResult['code'],
                    message: $progressResult['message'],
                    meta: $progressResult['meta'],
                );
            }

            $result = $this->unwrapToolActionData($progressResult['data']);
            $this->line("Reconfigured {$result['name']} on {$result['node']}.");

            return self::SUCCESS;
        }

        $result = $this->isGatewayCaller()
            ? $reconfigurer->reconfigure($tool, node: $node, app: $app, password: $password)
            : $this->reconfigureViaGateway($tool, node: $node, app: $app, password: $password);

        if ($result instanceof GatewayApiException) {
            return $this->failCommand(
                code: $result->errorCode() ?? 'gateway_unavailable',
                message: $result->getMessage() !== ''
                    ? $result->getMessage()
                    : 'Gateway connection is required to reconfigure tools.',
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
    private function reconfigureViaGateway(string $tool, ?string $node, ?string $app, ?string $password): array|GatewayApiException
    {
        try {
            $dto = app(GatewayConnector::class)
                ->send(new ReconfigureToolRequest(tool: $tool, app: $app, node: $node, password: $password))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        } catch (Throwable) {
            return new GatewayApiException(
                message: 'Gateway connection is required to reconfigure tools.',
                errorCode: 'gateway_unavailable',
                errorMeta: [],
            );
        }

        /** @var ToolReconfigureResponse $dto */
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
