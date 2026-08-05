<?php

declare(strict_types=1);

namespace App\Commands\Deploy;

use App\Exceptions\GatewayApiException;
use App\Services\Deploy\DeployTerminalFrame;
use App\Services\GatewayOperationStreamSubscriber;
use Orbit\Core\Progress\ProgressEventType;
use RuntimeException;

/** @mago-expect lint:cyclomatic-complexity */
final class DeployRunCommand extends DeployGatewayCommand
{
    #[\Override]
    protected $signature = 'deploy:run
        {instance? : Instance selector (app.instance)}
        {--detach : Start and return after the run is durable}
        {--json : Output JSON}
        {--stream-json : Stream newline-delimited JSON progress frames}';

    #[\Override]
    protected $description = 'Run the deployment pipeline for an instance.';

    public function handle(): int
    {
        $outputModeValidation = $this->validateProgressOutputMode();

        if ($outputModeValidation !== null) {
            return $outputModeValidation;
        }

        $instanceSelector = $this->requiredArgument('instance', 'instance', 'Instance is required.');

        if (is_int($instanceSelector)) {
            return $instanceSelector;
        }

        try {
            $response = $this->gatewayPost('/api/deploy/run', [
                'instance' => $instanceSelector,
                'detach' => $this->option('detach') === true,
            ]);
            $operation = $this->operationDescriptor($response);

            if ($this->option('detach') === true) {
                return $this->renderDetachedOperation($response, $operation);
            }

            return $this->subscribe($operation['uuid']);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }
    }

    private function subscribe(string $operationRunId): int
    {
        $terminal = new DeployTerminalFrame;

        app(GatewayOperationStreamSubscriber::class)->subscribe(
            $operationRunId,
            null,
            function (array $frame) use ($terminal): void {
                $type = $this->frameType($frame);
                $payload = $frame['payload'] ?? null;

                if (! is_array($payload)) {
                    throw GatewayApiException::streamMalformed(
                        new RuntimeException('Deployment operation frame payload must be an object.'),
                    );
                }

                /** @var array<string, mixed> $payload */
                if ($type === ProgressEventType::Complete || $type === ProgressEventType::Error) {
                    $terminal->capture($type, $payload);

                    return;
                }

                if ($this->wantsStreamingJson()) {
                    $this->renderStreamJsonProgressFrame($type, $payload);

                    return;
                }

                if (! $this->wantsJson()) {
                    $this->renderProgressFrame($type, $payload);
                }
            },
        );

        $terminalFrame = $terminal->frame();

        if ($terminalFrame === null) {
            return $this->renderFailure(
                'gateway_unavailable',
                'Deployment operation stream closed without a terminal frame.',
            );
        }

        return $this->renderProgressTerminalFrame($terminalFrame['type'], $terminalFrame['payload']);
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private function frameType(array $frame): ProgressEventType
    {
        $type = $frame['type'] ?? null;

        if (! is_string($type) || ! ProgressEventType::tryFrom($type) instanceof ProgressEventType) {
            throw GatewayApiException::streamMalformed(
                new RuntimeException('Deployment operation frame type is invalid.'),
            );
        }

        return ProgressEventType::from($type);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{uuid: string, stream_descriptor_url: string, events_url: string}
     */
    private function operationDescriptor(array $response): array
    {
        $operation = data_get($response, 'success.data.operation');

        if (! is_array($operation)) {
            throw GatewayApiException::streamMalformed(
                new RuntimeException('Deploy start response omitted the operation descriptor.'),
            );
        }

        $uuid = $operation['uuid'] ?? null;
        $streamDescriptorUrl = $operation['stream_descriptor_url'] ?? null;
        $eventsUrl = $operation['events_url'] ?? null;

        if (
            ! is_string($uuid)
            || trim($uuid) === ''
            || ! is_string($streamDescriptorUrl)
            || trim($streamDescriptorUrl) === ''
            || ! is_string($eventsUrl)
            || trim($eventsUrl) === ''
        ) {
            throw GatewayApiException::streamMalformed(
                new RuntimeException('Deploy start response contains an invalid operation descriptor.'),
            );
        }

        return [
            'uuid' => trim($uuid),
            'stream_descriptor_url' => trim($streamDescriptorUrl),
            'events_url' => trim($eventsUrl),
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array{uuid: string, stream_descriptor_url: string, events_url: string}  $operation
     */
    private function renderDetachedOperation(array $response, array $operation): int
    {
        if ($this->wantsStreamingJson()) {
            return $this->renderProgressTerminalFrame(ProgressEventType::Complete, [
                'exit_code' => 0,
                'data' => ['operation' => $operation],
            ]);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $this->line("Deployment operation queued: {$operation['uuid']}");

        return self::SUCCESS;
    }
}
