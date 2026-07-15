<?php

declare(strict_types=1);

namespace App\Services\Node;

use Illuminate\Support\Facades\Process;

class NodeGatewayBootstrapper
{
    /**
     * @param  list<string>  $arguments
     * @return array{exit_code: int, output: string}
     */
    public function run(array $arguments): array
    {
        if (! $this->gatewayRuntimeAvailable()) {
            return [
                'exit_code' => 1,
                'output' => json_encode([
                    'error' => [
                        'code' => 'gateway_unavailable',
                        'message' => 'Gateway artisan entry point is not available.',
                        'meta' => ['container' => 'orbit-gateway'],
                    ],
                ], JSON_THROW_ON_ERROR),
            ];
        }

        $result = Process::forever()->run(
            ['docker', 'exec', 'orbit-gateway', 'php', 'apps/gateway/artisan', ...$arguments],
        );

        $output = trim($result->output());

        if ($output === '') {
            $output = trim($result->errorOutput());
        }

        return [
            'exit_code' => $result->exitCode() ?? 1,
            'output' => $output,
        ];
    }

    private function gatewayRuntimeAvailable(): bool
    {
        return Process::run(
            ['docker', 'exec', 'orbit-gateway', 'test', '-f', 'apps/gateway/artisan'],
        )->successful();
    }
}
