<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Services\Docker\LocalDockerCommandContext;
use Symfony\Component\Process\Process;

final readonly class LocalAppRuntimeContainersProbe
{
    public function __construct(
        private LocalDockerCommandContext $docker,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function probe(): array
    {
        $dockerCheck = $this->runDocker(['docker', '--version'], timeout: 10);

        if (! $dockerCheck->isSuccessful()) {
            return [
                'status' => 'absent',
                'containers' => [],
                'error' => '',
                'stdout' => "orbit-container-scan:absent\n",
            ];
        }

        $scan = $this->runDocker([
            'docker',
            'container',
            'ls',
            '--all',
            '--filter',
            'label=orbit.managed=true',
            '--filter',
            'label=orbit.container.kind=app-runtime',
            '--format',
            '{{.Names}}\t{{.Label "orbit.app"}}',
        ], timeout: 20);

        if (! $scan->isSuccessful()) {
            $error = trim($scan->getErrorOutput());
            $error = $error !== '' ? $error : "docker container ls failed (ec={$scan->getExitCode()})";

            return $this->error($error);
        }

        $lines = array_values(array_filter(
            array_map(trim(...), explode("\n", trim($scan->getOutput()))),
            static fn (string $line): bool => $line !== '',
        ));

        return [
            'status' => 'present',
            'containers' => $this->containers($lines),
            'error' => '',
            'stdout' => "orbit-container-scan:present\n".$this->lineOutput($lines),
        ];
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{container_name: string, app_slug: string}>
     */
    private function containers(array $lines): array
    {
        $containers = [];

        foreach ($lines as $line) {
            $parts = explode(separator: "\t", string: $line, limit: 2);

            if (count($parts) !== 2 || trim($parts[1]) === '') {
                continue;
            }

            $containers[] = [
                'container_name' => trim($parts[0]),
                'app_slug' => trim($parts[1]),
            ];
        }

        return $containers;
    }

    /**
     * @return array<string, mixed>
     */
    private function error(string $error): array
    {
        return [
            'status' => 'error',
            'containers' => [],
            'error' => $error,
            'stdout' => "orbit-container-scan:error {$error}\n",
        ];
    }

    /**
     * @param  list<string>  $lines
     */
    private function lineOutput(array $lines): string
    {
        if ($lines === []) {
            return '';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<string>  $command
     */
    private function runDocker(array $command, int $timeout): Process
    {
        $process = new Process($command, null, $this->docker->environmentFor($command));
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }
}
