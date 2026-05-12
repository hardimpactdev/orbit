<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\ToolActionGatewayStreamClient;
use App\Services\Tools\ToolRegistryFailure;
use App\Support\Cli\RemoteProgressRenderer;
use App\Support\Cli\RemoteProgressReporter;
use App\Support\Tools\ToolActionProgressRunner;
use Throwable;

trait RunsToolActionProgress
{
    /**
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, code: string, message: string, meta: array<string, mixed>, data: array<string, mixed>}
     */
    protected function runLocalToolActionProgress(
        ToolActionProgressRunner $progress,
        string $title,
        string $doneFooter,
        string $failFooter,
        callable $operation,
        ?callable $successful = null,
    ): array {
        $reporter = new RemoteProgressReporter(new RemoteProgressRenderer($this->output));

        try {
            $result = $progress->run(
                reporter: $reporter,
                title: $title,
                operation: $operation,
            );
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

        $operationSucceeded = $successful === null || $successful($result) === true;
        $reporter->finish($operationSucceeded ? $doneFooter : $failFooter, $operationSucceeded);

        if (! $operationSucceeded) {
            return [
                'ok' => false,
                'code' => 'tool.action_failed',
                'message' => 'Tool action failed.',
                'meta' => [],
                'data' => $result,
            ];
        }

        return [
            'ok' => true,
            'data' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, code: string, message: string, meta: array<string, mixed>, data: array<string, mixed>}
     */
    protected function runGatewayToolActionProgress(
        ToolActionGatewayStreamClient $stream,
        string $action,
        ?string $tool,
        array $payload,
        string $unavailableMessage,
        string $defaultFooter,
    ): array {
        $renderer = new RemoteProgressRenderer($this->output);
        $completeData = [];
        $errorData = [];
        $footer = $defaultFooter;

        $result = $stream->run(
            action: $action,
            tool: $tool,
            payload: $payload,
            onEvent: function (string $event, array $payload) use ($renderer, &$completeData, &$errorData, &$footer): void {
                if ($event === 'tree') {
                    $title = is_string($payload['title'] ?? null) ? $payload['title'] : '';
                    $steps = is_array($payload['steps'] ?? null) ? $payload['steps'] : [];
                    $renderer->tree($title, $steps);

                    return;
                }

                if ($event === 'step') {
                    $renderer->step(
                        is_string($payload['key'] ?? null) ? $payload['key'] : '',
                        is_string($payload['status'] ?? null) ? $payload['status'] : '',
                        is_string($payload['message'] ?? null) ? $payload['message'] : null,
                    );

                    return;
                }

                if ($event === 'complete') {
                    $completeData = is_array($payload['data'] ?? null) ? $payload['data'] : [];

                    if (is_string($completeData['footer'] ?? null)) {
                        $footer = $completeData['footer'];
                    }

                    return;
                }

                if ($event === 'error') {
                    $errorData = is_array($payload['data'] ?? null) ? $payload['data'] : [];

                    if (is_string($errorData['footer'] ?? null)) {
                        $footer = $errorData['footer'];
                    }
                }
            },
            unavailableMessage: $unavailableMessage,
        );

        if ($result instanceof GatewayApiException) {
            return [
                'ok' => false,
                'code' => $result->errorCode() ?? 'gateway_unavailable',
                'message' => $result->getMessage() !== '' ? $result->getMessage() : $unavailableMessage,
                'meta' => $result->errorMeta(),
                'data' => $result->errorData(),
            ];
        }

        $renderer->finish($footer, $result === 0);

        if ($result !== 0) {
            return [
                'ok' => false,
                'code' => is_string($errorData['code'] ?? null) ? $errorData['code'] : 'tool.action_failed',
                'message' => is_string($errorData['message'] ?? null) ? $errorData['message'] : 'Tool action failed.',
                'meta' => is_array($errorData['meta'] ?? null) ? $errorData['meta'] : [],
                'data' => is_array($errorData['data'] ?? null) ? $errorData['data'] : $completeData,
            ];
        }

        return [
            'ok' => true,
            'data' => $completeData,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function unwrapToolActionData(array $data): array
    {
        return is_array($data['tool'] ?? null) ? $data['tool'] : $data;
    }
}
