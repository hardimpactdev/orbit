<?php

declare(strict_types=1);

namespace App\Services\Node;

use Symfony\Component\Process\Process;

class NodeGatewayBootstrapper
{
    /**
     * @param  list<string>  $arguments
     * @return array{exit_code: int, output: string}
     */
    public function run(array $arguments): array
    {
        $artisan = $this->gatewayArtisanPath();

        if (! is_file($artisan)) {
            return [
                'exit_code' => 1,
                'output' => json_encode([
                    'error' => [
                        'code' => 'gateway_bootstrap_unavailable',
                        'message' => 'Gateway artisan entry point is not available.',
                        'meta' => ['path' => $artisan],
                    ],
                ], JSON_THROW_ON_ERROR),
            ];
        }

        $process = new Process([PHP_BINARY, $artisan, ...$arguments], $this->repositoryRoot());
        $process->setTimeout(null);
        $process->run();

        $output = trim($process->getOutput());

        if ($output === '') {
            $output = trim($process->getErrorOutput());
        }

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'output' => $output,
        ];
    }

    private function gatewayArtisanPath(): string
    {
        return $this->repositoryRoot().'/apps/gateway/artisan';
    }

    private function repositoryRoot(): string
    {
        return dirname(base_path(), 2);
    }
}
