<?php

declare(strict_types=1);

namespace App\Services\RuntimeBackend;

use Symfony\Component\Process\Process;

final readonly class LocalRuntimeBackendProbe
{
    private const array PROVIDERS = ['docker', 'systemd', 'launchd'];

    /**
     * @return array<string, mixed>
     */
    public function check(mixed $provider): array
    {
        $provider = $this->provider($provider);
        $commands = match ($provider) {
            'docker' => [
                ['docker', 'info'],
            ],
            'launchd' => [
                ['launchctl', 'help'],
            ],
            default => [
                ['systemctl', '--version'],
            ],
        };

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
            'output' => match ($provider) {
                'docker' => 'Docker provider ready',
                'launchd' => 'launchd provider ready',
                default => 'systemd provider ready',
            },
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
