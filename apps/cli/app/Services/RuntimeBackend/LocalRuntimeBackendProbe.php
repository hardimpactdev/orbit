<?php

declare(strict_types=1);

namespace App\Services\RuntimeBackend;

use Symfony\Component\Process\Process;

final readonly class LocalRuntimeBackendProbe
{
    private const array PROVIDERS = ['docker', 'systemd'];

    /**
     * @return array<string, mixed>
     */
    public function check(mixed $provider): array
    {
        $provider = $this->provider($provider);
        $commands = $provider === 'docker'
            ? [
                ['docker', 'info'],
            ]
            : [
                ['systemctl', '--version'],
            ];

        foreach ($commands as $command) {
            $result = $this->run($command);

            if (! $result->isSuccessful()) {
                return [
                    'provider' => $provider,
                    'available' => false,
                    'exit_code' => $result->getExitCode(),
                    'output' => $this->output($result),
                ];
            }
        }

        return [
            'provider' => $provider,
            'available' => true,
            'exit_code' => 0,
            'output' => $provider === 'docker' ? 'Docker provider ready' : 'systemd provider ready',
        ];
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(15);
        $process->run();

        return $process;
    }

    private function provider(mixed $value): string
    {
        if (is_string($value) && in_array($value, self::PROVIDERS, strict: true)) {
            return $value;
        }

        throw new LocalRuntimeBackendProbeFailure(
            errorCode: 'validation_failed',
            message: 'Runtime backend provider is invalid.',
            meta: ['field' => 'provider'],
        );
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
