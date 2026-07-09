<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Services\Docker\LocalDockerCommandContext;
use Symfony\Component\Process\Process;

final readonly class LocalAppRuntimeExtensionsProbe
{
    public function __construct(
        private LocalDockerCommandContext $docker,
    ) {}

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function probe(mixed $container): array
    {
        $container = $this->container($container);
        $command = ['docker', 'exec', $container, 'php', '-m'];
        $process = new Process($command, null, $this->docker->environmentFor($command));
        $process->setTimeout(30);
        $process->run();

        return [
            'data' => [
                'container' => $container,
                'exit_code' => $process->getExitCode(),
                'stdout' => $process->getOutput(),
                'stderr' => $process->getErrorOutput(),
            ],
            'meta' => [],
        ];
    }

    private function container(mixed $value): string
    {
        if (is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $value) === 1) {
            return $value;
        }

        throw new LocalAppRuntimeExtensionsProbeFailure(
            errorCode: 'validation_failed',
            message: 'App runtime container name is invalid.',
            meta: ['field' => 'container'],
        );
    }
}
