<?php

declare(strict_types=1);

namespace App\Commands\Operation;

use App\Commands\Concerns\StreamsGatewayProgress;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Services\GatewayOperationFollower;
use App\Services\Updates\LocalUpdateResult;
use App\Services\Updates\LocalUpdateWorkflow;
use App\Services\Updates\UpdateAllHumanProgressRenderer;
use Orbit\Core\Progress\ProgressEventType;

final class UpdateAllCommand extends GatewayCommand
{
    use StreamsGatewayProgress;

    #[\Override]
    protected $signature = 'update:all {--json : Output JSON}';

    #[\Override]
    protected $description = 'Update every managed Orbit installation through the gateway.';

    public function handle(
        LocalUpdateWorkflow $localUpdates,
        UpdateAllHumanProgressRenderer $progress,
    ): int {
        if (! $this->wantsJson()) {
            return $this->handleHuman($localUpdates, $progress);
        }

        $localResult = $localUpdates->run();

        if (! $localResult->successful()) {
            return $this->renderLocalUpdateFailure($localResult);
        }

        try {
            $response = $this->gatewayPost('/api/update/all/start');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $eventsUrl = $this->eventsUrl($response);

        if ($eventsUrl === null) {
            return $this->renderFailure(
                'gateway_unavailable',
                'Gateway update operation did not provide an event stream URL.',
            );
        }

        return $this->followOperationProgress(
            $eventsUrl,
            fn (ProgressEventType $type, array $payload): int => $this->renderProgressTerminalFrame($type, $payload),
        );
    }

    private function handleHuman(
        LocalUpdateWorkflow $localUpdates,
        UpdateAllHumanProgressRenderer $progress,
    ): int {
        $progress->begin($this->output);

        $localResult = $localUpdates->run(
            /**
             * @param  array{successful: bool, exit_code: int, output: string}  $result
             */
            function (int $_index, string $_step, array $result) use ($progress): void {
                if ($result['successful']) {
                    $progress->localSucceeded($this->output);

                    return;
                }

                $progress->localFailed($this->output, $result['output']);
            },
        );

        if (! $localResult->successful()) {
            if ($localResult->status === LocalUpdateResult::STATUS_CHECKOUT_UNAVAILABLE) {
                $progress->localFailed($this->output);
            }

            $progress->finishFailure($this->output);

            return $this->renderLocalUpdateFailure($localResult);
        }

        $progress->gatewayStarting($this->output);

        try {
            $response = $this->gatewayPost('/api/update/all/start');
        } catch (GatewayApiException $exception) {
            $progress->gatewayFailed($this->output, $exception->getMessage());
            $progress->finishFailure($this->output);

            return $this->renderGatewayFailure($exception);
        }

        $eventsUrl = $this->eventsUrl($response);

        if ($eventsUrl === null) {
            $progress->gatewayFailed($this->output, 'Gateway update operation did not provide an event stream URL.');
            $progress->finishFailure($this->output);

            return $this->renderFailure(
                'gateway_unavailable',
                'Gateway update operation did not provide an event stream URL.',
            );
        }

        try {
            $terminal = app(GatewayOperationFollower::class)->follow(
                $eventsUrl,
                function (ProgressEventType $type, array $payload) use ($progress): void {
                    if ($type === ProgressEventType::Complete || $type === ProgressEventType::Error) {
                        return;
                    }

                    $progress->applyEvent($this->output, $type, $payload);
                },
            );
        } catch (GatewayApiException $exception) {
            $progress->gatewayFailed($this->output, $exception->getMessage());
            $progress->finishFailure($this->output);

            return $this->renderGatewayFailure($exception);
        }

        if ($terminal['type'] === ProgressEventType::Error) {
            $progress->gatewayFailed($this->output, $this->operationErrorMessage($terminal['payload']));
            $progress->finishFailure($this->output);

            return $this->renderOperationError($terminal['payload']);
        }

        $progress->finishSuccess($this->output, $this->terminalTargetVersion($terminal['payload']));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function eventsUrl(array $response): ?string
    {
        $success = $response['success'] ?? null;
        $data = is_array($success) ? ($success['data'] ?? null) : null;
        $eventsUrl = is_array($data) ? ($data['events_url'] ?? null) : null;

        if (! is_string($eventsUrl)) {
            return null;
        }

        $eventsUrl = trim($eventsUrl);

        return $eventsUrl === '' ? null : $eventsUrl;
    }

    private function renderLocalUpdateFailure(LocalUpdateResult $result): int
    {
        if ($result->status === LocalUpdateResult::STATUS_CHECKOUT_UNAVAILABLE) {
            if (! $this->wantsJson()) {
                $this->line('Local Orbit checkout cannot be updated.');

                return self::FAILURE;
            }

            return $this->renderFailure(
                'local_checkout_unavailable',
                'Local Orbit checkout cannot be updated.',
                ['path' => $result->checkoutPath ?? ''],
            );
        }

        $data = $result->output !== '' ? ['output' => $result->output] : [];

        if (! $this->wantsJson()) {
            $this->line('Failed to update local Orbit CLI.');

            if ($result->output !== '') {
                $this->line($result->output);
            }

            return self::FAILURE;
        }

        return $this->renderFailure(
            'local_update_failed',
            'Failed to update local Orbit checkout.',
            ['failed_step' => $result->failedStep ?? 'unknown'],
            $data,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderOperationError(array $payload): int
    {
        $data = $this->frameData($payload);
        $code = $this->frameString($data, 'code') ?? $this->frameString($payload, 'code') ?? 'gateway_stream_error';
        $message = $this->frameString($data, 'message') ?? $this->frameString($payload, 'message') ?? 'Gateway progress stream failed.';
        $meta = $this->frameArray($data, 'meta') ?? $this->frameArray($payload, 'meta') ?? [];

        return $this->renderFailure($code, $message, $meta);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function terminalTargetVersion(array $payload): ?string
    {
        return $this->frameString($this->frameData($payload), 'target_version')
            ?? $this->frameString($payload, 'target_version');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function operationErrorMessage(array $payload): string
    {
        $data = $this->frameData($payload);

        return $this->frameString($data, 'message')
            ?? $this->frameString($payload, 'message')
            ?? 'Gateway progress stream failed.';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function frameData(array $payload): array
    {
        $data = $payload['data'] ?? null;

        return is_array($data) ? $data : $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function frameString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function frameArray(array $payload, string $key): ?array
    {
        $value = $payload[$key] ?? null;

        return is_array($value) ? $value : null;
    }
}
