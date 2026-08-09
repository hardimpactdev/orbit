<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

use App\Exceptions\GatewayApiException;
use App\Services\GatewayOperationStreamPublisher;
use App\Services\Processes\LocalProcessLogsOperationStream;
use Orbit\Core\Operations\OperationStreamFrameType;
use Symfony\Component\Process\Process;

final readonly class ApplicationLogStreamPublisher
{
    private const int OPERATION_STREAM_POLL_INTERVAL_MICROSECONDS = 1_000_000;

    private const int INITIAL_SUBSCRIBER_GRACE_MICROSECONDS = 1_000_000;

    private const int OPERATION_STREAM_POLL_SLEEP_MICROSECONDS = 100_000;

    public function __construct(
        private GatewayOperationStreamPublisher $operationStreams,
    ) {}

    /**
     * @param  callable(string): void  $onOutput
     * @param  list<string>  $command
     */
    public function stream(LocalApplicationLogPayload $input, array $command, callable $onOutput): int
    {
        if ($input->operationStream === null) {
            $process = new Process($command);
            $process->setTimeout(null);

            return $process->run(static function (string $_type, string $buffer) use ($onOutput): void {
                $onOutput($buffer);
            });
        }

        return $this->streamOperationFrames($input, $command, $onOutput);
    }

    /**
     * @param  callable(string): void  $onOutput
     * @param  list<string>  $command
     */
    private function streamOperationFrames(LocalApplicationLogPayload $input, array $command, callable $onOutput): int
    {
        $this->awaitInitialSubscriber($input);

        $process = new Process($command);
        $process->setTimeout(null);
        $process->start();

        $sequence = 0;
        $nextStopPollAt = microtime(true) + (self::OPERATION_STREAM_POLL_INTERVAL_MICROSECONDS / 1_000_000);

        while ($process->isRunning()) {
            $this->publishIncrementalOutput($process, $input, $onOutput, $sequence);

            if (microtime(true) >= $nextStopPollAt) {
                $nextStopPollAt = microtime(true) + (self::OPERATION_STREAM_POLL_INTERVAL_MICROSECONDS / 1_000_000);

                if ($this->shouldStop($input)) {
                    $process->stop(1);

                    return 0;
                }
            }

            usleep(self::OPERATION_STREAM_POLL_SLEEP_MICROSECONDS);
        }

        $this->publishIncrementalOutput($process, $input, $onOutput, $sequence);

        return $process->getExitCode() ?? 1;
    }

    private function awaitInitialSubscriber(LocalApplicationLogPayload $input): void
    {
        $deadline = microtime(true) + (self::INITIAL_SUBSCRIBER_GRACE_MICROSECONDS / 1_000_000);

        while (microtime(true) < $deadline) {
            if (! $this->shouldStop($input)) {
                return;
            }

            usleep(self::OPERATION_STREAM_POLL_SLEEP_MICROSECONDS);
        }
    }

    /**
     * @param  callable(string): void  $onOutput
     */
    private function publishIncrementalOutput(
        Process $process,
        LocalApplicationLogPayload $input,
        callable $onOutput,
        int &$sequence,
    ): void {
        $stdout = $process->getIncrementalOutput();

        if ($stdout !== '') {
            $this->publishChunk($input, OperationStreamFrameType::Stdout, $stdout, $onOutput, $sequence);
        }

        $stderr = $process->getIncrementalErrorOutput();

        if ($stderr !== '') {
            $this->publishChunk($input, OperationStreamFrameType::Stderr, $stderr, $onOutput, $sequence);
        }
    }

    /**
     * @param  callable(string): void  $onOutput
     */
    private function publishChunk(
        LocalApplicationLogPayload $input,
        OperationStreamFrameType $type,
        string $buffer,
        callable $onOutput,
        int &$sequence,
    ): void {
        $stream = $input->operationStream;

        if ($stream === null) {
            return;
        }

        $onOutput($buffer);
        $sequence++;

        try {
            $this->operationStreams->publishProcessLogChunk(
                stream: $this->processStreamAdapter($stream),
                sequence: $sequence,
                type: $type,
                output: $buffer,
            );
        } catch (GatewayApiException $exception) {
            throw new LocalApplicationLogFailure(
                errorCode: 'operation_stream_publish_failed',
                message: 'Application log operation stream publish failed.',
                meta: [
                    'reason' => $exception->cliFailureCode(),
                ],
            );
        }
    }

    private function shouldStop(LocalApplicationLogPayload $input): bool
    {
        $stream = $input->operationStream;

        if ($stream === null) {
            return false;
        }

        try {
            return $this->operationStreams->shouldStop($this->processStreamAdapter($stream));
        } catch (GatewayApiException $exception) {
            throw new LocalApplicationLogFailure(
                errorCode: 'operation_stream_stop_decision_failed',
                message: 'Application log operation stream stop decision failed.',
                meta: [
                    'reason' => $exception->cliFailureCode(),
                ],
            );
        }
    }

    private function processStreamAdapter(LocalApplicationLogOperationStream $stream): LocalProcessLogsOperationStream
    {
        $adapted = LocalProcessLogsOperationStream::from([
            'operation_uuid' => $stream->operationUuid,
            'channel' => $stream->channel,
            'publish_endpoint' => $stream->publishEndpoint,
            'stop_decision_endpoint' => $stream->stopDecisionEndpoint,
            'gateway_url' => $stream->gatewayUrl,
            'ca_pem_path' => $stream->caPemPath,
            'publisher_token' => $stream->publisherToken,
        ]);

        if (! $adapted instanceof LocalProcessLogsOperationStream) {
            throw new LocalApplicationLogFailure(
                errorCode: 'validation_failed',
                message: 'Application log operation stream metadata is invalid.',
                meta: ['field' => 'operation_stream'],
            );
        }

        return $adapted;
    }
}
