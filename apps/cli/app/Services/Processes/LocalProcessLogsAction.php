<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Exceptions\GatewayApiException;
use App\Services\GatewayOperationStreamPublisher;
use Orbit\Core\Operations\OperationStreamFrameType;
use Symfony\Component\Process\Process;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class LocalProcessLogsAction
{
    private const int OPERATION_STREAM_POLL_INTERVAL_MICROSECONDS = 1_000_000;

    private const int INITIAL_SUBSCRIBER_GRACE_MICROSECONDS = 1_000_000;

    private const int OPERATION_STREAM_POLL_SLEEP_MICROSECONDS = 100_000;

    private const int SYSTEMD_FOLLOW_FLAG_OFFSET = 6;

    private const int DOCKER_FOLLOW_FLAG_OFFSET = 4;

    private const int LAUNCHD_FOLLOW_FLAG_OFFSET = 3;

    public function __construct(
        private GatewayOperationStreamPublisher $operationStreams,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function read(array $payload): array
    {
        $input = LocalProcessLogsPayload::from($payload);
        $result = $this->run($this->command($input));

        if (! $result->isSuccessful()) {
            throw new LocalProcessLogsFailure(
                errorCode: 'process_logs_failed',
                message: 'Process logs could not be read.',
                meta: [
                    'backend' => $input->backend,
                    'runtime_unit' => $input->runtimeUnit,
                    'exit_code' => $result->getExitCode(),
                    'stderr' => trim($result->getErrorOutput()),
                ],
            );
        }

        return [
            'data' => [
                'backend' => $input->backend,
                'runtime_unit' => $input->runtimeUnit,
                'output' => $result->getOutput().$result->getErrorOutput(),
            ],
            'meta' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(string): void  $onOutput
     */
    public function stream(array $payload, callable $onOutput): int
    {
        $input = LocalProcessLogsPayload::from($payload);

        if ($input->operationStream !== null) {
            return $this->streamOperationFrames($input, $onOutput);
        }

        $process = new Process($this->command($input));
        $process->setTimeout(null);

        return $process->run(static function (string $_type, string $buffer) use ($onOutput): void {
            $onOutput($buffer);
        });
    }

    /**
     * @param  callable(string): void  $onOutput
     */
    private function streamOperationFrames(LocalProcessLogsPayload $input, callable $onOutput): int
    {
        $this->awaitInitialSubscriber($input);

        $process = new Process($this->command($input));
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

                    return self::successExitCode();
                }
            }

            usleep(self::OPERATION_STREAM_POLL_SLEEP_MICROSECONDS);
        }

        $this->publishIncrementalOutput($process, $input, $onOutput, $sequence);

        return $process->getExitCode() ?? 1;
    }

    private function awaitInitialSubscriber(LocalProcessLogsPayload $input): void
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
        LocalProcessLogsPayload $input,
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
        LocalProcessLogsPayload $input,
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
                stream: $stream,
                sequence: $sequence,
                type: $type,
                output: $buffer,
            );
        } catch (GatewayApiException $exception) {
            throw new LocalProcessLogsFailure(
                errorCode: 'operation_stream_publish_failed',
                message: 'Process logs operation stream publish failed.',
                meta: [
                    'reason' => $exception->cliFailureCode(),
                ],
            );
        }
    }

    private function shouldStop(LocalProcessLogsPayload $input): bool
    {
        $stream = $input->operationStream;

        if ($stream === null) {
            return false;
        }

        try {
            return $this->operationStreams->shouldStop($stream);
        } catch (GatewayApiException $exception) {
            throw new LocalProcessLogsFailure(
                errorCode: 'operation_stream_stop_decision_failed',
                message: 'Process logs operation stream stop decision failed.',
                meta: [
                    'reason' => $exception->cliFailureCode(),
                ],
            );
        }
    }

    private static function successExitCode(): int
    {
        return 0;
    }

    /**
     * @return list<string>
     */
    private function command(LocalProcessLogsPayload $input): array
    {
        $command = $this->snapshotCommand($input);

        if ($input->follow) {
            return $this->withFollowFlag($input, $command);
        }

        return $command;
    }

    /**
     * @return list<string>
     */
    private function snapshotCommand(LocalProcessLogsPayload $payload): array
    {
        return match ($payload->backend) {
            'docker' => [
                'docker',
                'logs',
                '--tail',
                (string) $payload->lines,
                $payload->runtimeUnit,
            ],
            'docker-swarm' => [
                'docker',
                'service',
                'logs',
                '--tail',
                (string) $payload->lines,
                $payload->runtimeUnit,
            ],
            'systemd' => [
                'sudo',
                'journalctl',
                '-u',
                $payload->systemdServiceName(),
                '-n',
                (string) $payload->lines,
                '--no-pager',
                '--output=short-iso',
            ],
            'launchd' => [
                'tail',
                '-n',
                (string) $payload->lines,
                $payload->stdoutPath ?? '',
                $payload->stderrPath ?? '',
            ],
            default => throw new LocalProcessLogsFailure(
                errorCode: 'validation_failed',
                message: 'Process logs backend is invalid.',
                meta: ['field' => 'backend'],
            ),
        };
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    private function withFollowFlag(LocalProcessLogsPayload $payload, array $command): array
    {
        if ($payload->backend === 'systemd') {
            array_splice($command, offset: self::SYSTEMD_FOLLOW_FLAG_OFFSET, length: 0, replacement: '-f');

            return $command;
        }

        if ($payload->backend === 'launchd') {
            array_splice($command, offset: self::LAUNCHD_FOLLOW_FLAG_OFFSET, length: 0, replacement: '-f');

            return $command;
        }

        array_splice($command, offset: self::DOCKER_FOLLOW_FLAG_OFFSET, length: 0, replacement: '--follow');

        return $command;
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        return $process;
    }
}
