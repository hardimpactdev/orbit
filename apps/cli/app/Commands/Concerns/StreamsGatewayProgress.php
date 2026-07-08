<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Exceptions\GatewayApiException;
use App\Services\GatewayOperationFollower;
use App\Services\GatewayStreamClient;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Progress\ProgressEventType;
use Orbit\Core\Progress\StreamedStepTree;

/**
 * Provides streamProgress() for commands that consume gateway SSE progress streams.
 *
 * Usage: add `use StreamsGatewayProgress;` to a GatewayCommand subclass.
 *
 * In --json mode the stream is consumed silently and only the terminal
 * (complete / error) frame is emitted.
 * In human mode the SSE tree/step frames drive an animated {@see StreamedStepTree}:
 * the idle tree paints on the first `tree` frame, the active step animates while
 * the stream blocks, and the terminal frame settles the footer.
 */
trait StreamsGatewayProgress
{
    private ?StreamedStepTree $progressTree = null;

    /**
     * Stream progress events from the gateway path.
     *
     * $onFinalFrame is called once with the terminal (complete / error) frame payload
     * when the stream ends normally. The trait handles output for intermediate frames.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(ProgressEventType, array<string, mixed>): int  $onFinalFrame
     */
    protected function streamProgress(
        string $path,
        array $payload,
        callable $onFinalFrame,
        string $method = 'post',
    ): int {
        $client = $this->gatewayStreamClient();
        $wantsJson = $this->wantsJson();
        $wantsStreamJson = $this->wantsStreamingJson();

        $finalType = null;
        $finalPayload = [];
        $streamStarted = false;

        if ($wantsJson && $wantsStreamJson) {
            return $this->renderFailure(
                'validation_failed',
                'Use either --json or --stream-json, not both.',
                ['fields' => ['json', 'stream-json'], 'reason' => 'conflicting_options'],
            );
        }

        try {
            $client->streamEvents(
                path: $path,
                payload: $payload,
                onEvent: function (ProgressEventType $type, array $eventPayload) use (
                    $wantsJson,
                    $wantsStreamJson,
                    &$finalType,
                    &$finalPayload,
                    &$streamStarted,
                ): void {
                    $streamStarted = true;

                    if ($type === ProgressEventType::Complete || $type === ProgressEventType::Error) {
                        $finalType = $type;
                        $finalPayload = $eventPayload;

                        return;
                    }

                    if ($wantsStreamJson) {
                        $this->renderStreamJsonProgressFrame($type, $eventPayload);

                        return;
                    }

                    if ($wantsJson) {
                        // Intermediate frames are silent in --json mode.
                        return;
                    }

                    $this->renderProgressFrame($type, $eventPayload);
                },
                method: $method,
            );
        } catch (GatewayApiException $exception) {
            if ($wantsStreamJson && $streamStarted) {
                return $this->renderStreamJsonTransportFailure($exception);
            }

            return $this->renderGatewayFailure($exception);
        }

        if ($finalType !== null) {
            return $onFinalFrame($finalType, $finalPayload);
        }

        return $this->renderFailure(
            'gateway_unavailable',
            'Gateway progress stream closed without a terminal frame.',
        );
    }

    /**
     * Follow a durable operation event journal and render it with the same
     * progress frame semantics used by streaming gateway commands.
     *
     * @param  callable(ProgressEventType, array<string, mixed>): int  $onFinalFrame
     */
    protected function followOperationProgress(string $eventsUrl, callable $onFinalFrame): int
    {
        $follower = app(GatewayOperationFollower::class);
        $wantsJson = $this->wantsJson();

        try {
            $terminal = $follower->follow(
                $eventsUrl,
                function (ProgressEventType $type, array $eventPayload) use ($wantsJson): void {
                    if ($type === ProgressEventType::Complete || $type === ProgressEventType::Error) {
                        return;
                    }

                    if ($wantsJson) {
                        return;
                    }

                    $this->renderProgressFrame($type, $eventPayload);
                },
            );
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $onFinalFrame($terminal['type'], $terminal['payload']);
    }

    /**
     * Stream progress events and return the terminal frame without rendering it.
     *
     * This is used by commands that need to inspect a gateway-authored terminal
     * payload before deciding their next adapter step, such as interactive
     * doctor resolution.
     *
     * @param  array<string, mixed>  $payload
     * @return array{type: ProgressEventType, payload: array<string, mixed>}|int
     */
    protected function captureProgressTerminalFrame(string $path, array $payload): array|int
    {
        $client = $this->gatewayStreamClient();
        $wantsJson = $this->wantsJson();

        $finalType = null;
        $finalPayload = [];

        try {
            $client->streamEvents(
                path: $path,
                payload: $payload,
                onEvent: function (ProgressEventType $type, array $eventPayload) use (
                    $wantsJson,
                    &$finalType,
                    &$finalPayload,
                ): void {
                    if ($type === ProgressEventType::Complete || $type === ProgressEventType::Error) {
                        $finalType = $type;
                        $finalPayload = $eventPayload;

                        return;
                    }

                    if ($wantsJson) {
                        return;
                    }

                    $this->renderProgressFrame($type, $eventPayload);
                },
            );
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($finalType instanceof ProgressEventType) {
            if (! $wantsJson && $this->progressTree?->isStarted()) {
                $data = $this->frameData($finalPayload);
                $footer = $this->frameString($data, 'footer') ?? $this->frameString($finalPayload, 'footer');

                $this->progressTree->finish(
                    $footer ?? ($finalType === ProgressEventType::Complete ? 'Done' : 'Failed'),
                    success: $finalType === ProgressEventType::Complete,
                );
            }

            return [
                'type' => $finalType,
                'payload' => $finalPayload,
            ];
        }

        return $this->renderFailure(
            'gateway_unavailable',
            'Gateway progress stream closed without a terminal frame.',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function renderProgressTerminalFrame(ProgressEventType $type, array $payload): int
    {
        if ($this->wantsStreamingJson()) {
            return $type === ProgressEventType::Complete
                ? $this->renderStreamJsonCompleteFrame($payload)
                : $this->renderStreamJsonErrorFrame($payload);
        }

        if ($this->wantsJson()) {
            $this->outputJsonLine([
                'event' => $type->value,
                'data' => $payload,
            ]);

            return $type === ProgressEventType::Complete ? self::SUCCESS : self::FAILURE;
        }

        if ($type === ProgressEventType::Error) {
            return $this->renderProgressErrorFrame($payload);
        }

        $data = $this->frameData($payload);
        $footer = $this->frameString($data, 'footer') ?? $this->frameString($payload, 'footer');

        if ($this->progressTree?->isStarted()) {
            $this->progressTree->finish($footer ?? 'Done', success: true);

            return self::SUCCESS;
        }

        if ($footer !== null) {
            $this->line($footer);

            return self::SUCCESS;
        }

        return $this->renderSuccess($data);
    }

    private function gatewayStreamClient(): mixed
    {
        $client = app(GatewayStreamClient::class);

        if (! method_exists($client, 'withNodeTransportPreference')) {
            return $client;
        }

        if (! method_exists($this, 'nodeTransportPreference')) {
            return $client;
        }

        return $client->withNodeTransportPreference($this->nodeTransportPreference());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function renderStreamJsonProgressFrame(ProgressEventType $type, array $payload): void
    {
        $this->outputJsonLine([
            'event' => $type->value,
            'data' => $payload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderStreamJsonCompleteFrame(array $payload): int
    {
        $success = JsonEnvelope::success(
            $this->streamSuccessData($payload),
            $this->streamSuccessMeta($payload),
        )['success'];

        $this->outputJsonLine([
            'event' => ProgressEventType::Complete->value,
            'success' => $success,
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderStreamJsonErrorFrame(array $payload): int
    {
        $this->outputJsonLine([
            'event' => ProgressEventType::Error->value,
            'error' => $this->streamErrorPayload($payload),
        ]);

        return self::FAILURE;
    }

    private function renderStreamJsonTransportFailure(GatewayApiException $exception): int
    {
        if ($exception->hasGatewayError()) {
            return $this->renderStreamJsonErrorFrame([
                'data' => array_filter(
                    [
                        'code' => $exception->gatewayErrorCode() ?? $exception->cliFailureCode(),
                        'message' => $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                        'meta' => $exception->gatewayErrorMeta(),
                        'data' => $exception->gatewayErrorData(),
                    ],
                    fn (mixed $value): bool => $value !== [],
                ),
            ]);
        }

        return $this->renderStreamJsonErrorFrame([
            'data' => [
                'code' => $exception->cliFailureCode(),
                'message' => $exception->getMessage(),
                'meta' => [],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function streamSuccessData(array $payload): array
    {
        $data = $this->frameData($payload);
        $success = $data['success'] ?? null;

        if (is_array($success) && is_array($success['data'] ?? null)) {
            return $success['data'];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function streamSuccessMeta(array $payload): array
    {
        $data = $this->frameData($payload);
        $success = $data['success'] ?? null;

        if (is_array($success) && is_array($success['meta'] ?? null)) {
            return $success['meta'];
        }

        return $this->frameArray($payload, 'meta') ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function streamErrorPayload(array $payload): array
    {
        $data = $this->frameData($payload);
        $error = $data['error'] ?? null;

        if (is_array($error)) {
            return [
                'code' => $this->frameString($error, 'code') ?? 'gateway_stream_error',
                'message' => $this->frameString($error, 'message') ?? 'Gateway progress stream failed.',
                'meta' => $this->frameArray($error, 'meta') ?? [],
                ...($this->frameArray($error, 'data') !== null ? ['data' => $this->frameArray($error, 'data')] : []),
            ];
        }

        $errorPayload = [
            'code' =>
                $this->frameString($data, 'code') ?? $this->frameString($payload, 'code') ?? 'gateway_stream_error',
            'message' =>
                $this->frameString($data, 'message') ?? $this->frameString($payload, 'message')
                    ?? 'Gateway progress stream failed.',
            'meta' => $this->frameArray($data, 'meta') ?? $this->frameArray($payload, 'meta') ?? [],
        ];

        $diagnosticData = $this->frameArray($data, 'data');

        if ($diagnosticData !== null) {
            $errorPayload['data'] = $diagnosticData;
        }

        return $errorPayload;
    }

    /**
     * @param  array<string, mixed>  $eventPayload
     */
    private function renderProgressFrame(ProgressEventType $type, array $eventPayload): void
    {
        if ($type === ProgressEventType::Tree) {
            $title =
                $this->frameString($eventPayload, 'title') ?? $this->frameString($eventPayload, 'name') ?? 'Working';

            $steps = is_array($eventPayload['steps'] ?? null) ? $eventPayload['steps'] : [];

            $this->progressTree ??= new StreamedStepTree($this->output);
            $this->progressTree->tree($title, array_values(array_filter($steps, is_array(...))));

            return;
        }

        if ($type === ProgressEventType::Step && $this->progressTree !== null) {
            $key = $this->frameString($eventPayload, 'key');

            if ($key === null) {
                return;
            }

            $this->progressTree->step(
                $key,
                $this->frameString($eventPayload, 'status') ?? 'progress',
                $this->frameString($eventPayload, 'message'),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderProgressErrorFrame(array $payload): int
    {
        $data = $this->frameData($payload);
        $code = $this->frameString($data, 'code') ?? $this->frameString($payload, 'code') ?? 'gateway_stream_error';
        $message =
            $this->frameString($data, 'message') ?? $this->frameString($payload, 'message')
                ?? 'Gateway progress stream failed.';
        $meta = $this->frameArray($data, 'meta') ?? $this->frameArray($payload, 'meta') ?? [];

        if ($this->progressTree?->isStarted()) {
            $this->progressTree->finish($message, success: false);

            return self::FAILURE;
        }

        return $this->renderFailure($code, $message, $meta);
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
