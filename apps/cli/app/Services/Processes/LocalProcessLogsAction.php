<?php

declare(strict_types=1);

namespace App\Services\Processes;

use Symfony\Component\Process\Process;

final readonly class LocalProcessLogsAction
{
    private const int SYSTEMD_FOLLOW_FLAG_OFFSET = 6;

    private const int DOCKER_FOLLOW_FLAG_OFFSET = 4;

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
        $process = new Process($this->command($input));
        $process->setTimeout(null);

        return $process->run(function (string $_type, string $buffer) use ($onOutput): void {
            $onOutput($buffer);
        });
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
