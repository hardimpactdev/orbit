<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Exceptions\GatewayApiException;
use App\Services\GatewayStreamClient;
use Orbit\Core\Progress\ProgressEventType;

/**
 * Provides streamProgress() for commands that consume gateway SSE progress streams.
 *
 * Usage: add `use StreamsGatewayProgress;` to a GatewayCommand subclass.
 *
 * In --json mode the stream is consumed silently and only the terminal
 * (complete / error) frame is emitted as a canonical envelope.
 * In human mode each tree/step line is written to the console as it arrives.
 */
trait StreamsGatewayProgress
{
    /**
     * Stream progress events from the gateway path.
     *
     * $onFinalFrame is called once with the terminal (complete / error) frame payload
     * when the stream ends normally. The trait handles output for intermediate frames.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(ProgressEventType, array<string, mixed>): int  $onFinalFrame
     */
    protected function streamProgress(string $path, array $payload, callable $onFinalFrame): int
    {
        $client = app(GatewayStreamClient::class);
        $wantsJson = $this->wantsJson();

        $finalType = null;
        $finalPayload = [];

        try {
            $client->streamEvents(
                path: $path,
                payload: $payload,
                onEvent: function (ProgressEventType $type, array $eventPayload) use ($wantsJson, &$finalType, &$finalPayload): void {
                    if ($type === ProgressEventType::Complete || $type === ProgressEventType::Error) {
                        $finalType = $type;
                        $finalPayload = $eventPayload;

                        return;
                    }

                    if ($wantsJson) {
                        // Intermediate frames are silent in --json mode.
                        return;
                    }

                    $this->renderProgressFrame($type, $eventPayload);
                },
            );
        } catch (GatewayApiException $exception) {
            return $this->renderFailure(
                $exception->cliFailureCode(),
                $exception->getMessage(),
            );
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
     * @param  array<string, mixed>  $eventPayload
     */
    private function renderProgressFrame(ProgressEventType $type, array $eventPayload): void
    {
        $label = match ($type) {
            ProgressEventType::Tree => '[tree]',
            ProgressEventType::Step => '[step]',
            default => "[{$type->value}]",
        };

        $message = $eventPayload['message'] ?? $eventPayload['name'] ?? '';

        if (is_string($message) && $message !== '') {
            $this->line("{$label} {$message}");
        } else {
            $this->line($label);
        }
    }
}
