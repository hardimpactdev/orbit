<?php

declare(strict_types=1);

namespace App\Services\RuntimeBackend;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

final readonly class LocalGatewayRuntimeBackendProbe
{
    private const string CONTAINER_PATTERN = '/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}\z/';

    /**
     * @return array<string, mixed>
     */
    public function check(mixed $container): array
    {
        $container = $this->container($container);
        $docker = $this->run(['docker', 'info']);

        if ($docker['missing']) {
            return $this->result('no_docker', false, false, $docker['exit_code'], $docker['output']);
        }

        if ($docker['exit_code'] !== 0) {
            return $this->result('daemon_unavailable', false, false, $docker['exit_code'], $docker['output']);
        }

        $state = $this->run([
            'docker',
            'container',
            'inspect',
            '--format',
            '{{.State.Running}}',
            $container,
        ]);

        if ($state['exit_code'] !== 0) {
            return $this->result('available', false, false, $state['exit_code'], $state['output']);
        }

        $running = trim($state['output']) === 'true';

        return $this->result('available', true, $running, 0, "available\ttrue\t".($running ? 'true' : 'false'));
    }

    private function container(mixed $container): string
    {
        if (is_string($container) && preg_match(self::CONTAINER_PATTERN, $container) === 1) {
            return $container;
        }

        throw new LocalRuntimeBackendProbeFailure(
            errorCode: 'validation_failed',
            message: 'Gateway runtime container name is invalid.',
            meta: ['field' => 'container'],
        );
    }

    /**
     * @param  list<string>  $command
     * @return array{exit_code: int, output: string, missing: bool}
     */
    private function run(array $command): array
    {
        try {
            $process = new Process($command);
            $process->setTimeout(15);
            $process->run();

            return [
                'exit_code' => $process->getExitCode() ?? 1,
                'output' => $this->output($process),
                'missing' => false,
            ];
        } catch (ProcessStartFailedException $exception) {
            return [
                'exit_code' => 127,
                'output' => $exception->getMessage(),
                'missing' => true,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function result(string $status, bool $exists, bool $running, int $exitCode, string $output): array
    {
        return [
            'runtime_status' => $status,
            'container_exists' => $exists,
            'container_running' => $running,
            'exit_code' => $exitCode,
            'output' => trim($output),
        ];
    }

    private function output(Process $process): string
    {
        $output = trim($process->getErrorOutput());

        if ($output !== '') {
            return $output;
        }

        return trim($process->getOutput());
    }
}
