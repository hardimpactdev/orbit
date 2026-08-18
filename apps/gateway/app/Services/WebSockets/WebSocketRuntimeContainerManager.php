<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\RemoteShell\RunsInternalCommands;
use JsonException;
use RuntimeException;
use Throwable;

final readonly class WebSocketRuntimeContainerManager
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
        private ?WebSocketRuntimeContainerApplyWarnings $applyWarnings = null,
    ) {}

    public function apply(Node $node, WebSocketRuntimeContainer $container): void
    {
        $result = $this->runRuntimeAction(
            node: $node,
            action: 'container:apply',
            payload: [
                'spec' => [
                    ...$container->spec(),
                    'expected_hash' => $container->specHash(),
                ],
            ],
            operation: WebSocketRuntimeContainerApplyWarnings::APPLY_OPERATION,
        );

        if ($result->successful()) {
            $this->applyWarnings()->record($node, $result);

            return;
        }

        $this->throwRuntimeFailure($node, $container->name(), 'apply', $result);
    }

    public function remove(Node $node, string $containerName): bool
    {
        $result = $this->runRuntimeAction(
            node: $node,
            action: 'container:remove',
            payload: [
                'container' => $containerName,
            ],
            operation: 'websocket-runtime-container-remove',
        );

        return $result->successful();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function runRuntimeAction(Node $node, string $action, array $payload, string $operation): RemoteShellResult
    {
        try {
            return $this->localExecutor->runInternal(
                node: $node,
                commandName: 'internal:websocket-runtime',
                arguments: [$action],
                transportOptions: [
                    'throw' => false,
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => $operation,
                    ],
                    'input' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'strict' => false,
                    'timeout' => 120,
                ],
            );
        } catch (JsonException|Throwable $exception) {
            return new RemoteShellResult(
                exitCode: 1,
                stdout: '',
                stderr: $exception->getMessage(),
                durationMs: 0,
            );
        }
    }

    private function applyWarnings(): WebSocketRuntimeContainerApplyWarnings
    {
        return $this->applyWarnings ?? app(WebSocketRuntimeContainerApplyWarnings::class);
    }

    private function throwRuntimeFailure(
        Node $node,
        string $container,
        string $action,
        RemoteShellResult $result,
    ): never {
        $data = RemoteShellSuccessData::errorFromJsonEnvelope($result);
        $message = $data['message'] ?? null;

        if (! is_string($message) || trim($message) === '') {
            $output = trim($result->errorOutput().' '.$result->stdout);
            $message = $output !== '' ? $output : 'unknown error';
        }

        throw new RuntimeException("Failed to {$action} {$container} container on {$node->name}: {$message}");
    }
}
