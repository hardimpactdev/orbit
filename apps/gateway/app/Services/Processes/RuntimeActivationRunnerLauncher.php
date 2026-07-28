<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\OperationRun;
use App\Services\Gateway\GatewaySwarmStackRenderer;
use App\Services\Operations\UpdateRunnerImageResolver;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final readonly class RuntimeActivationRunnerLauncher
{
    private const string CONTAINER_CONFIG_ROOT = '/home/orbit/.config/orbit';

    public function __construct(
        private UpdateRunnerImageResolver $images,
    ) {}

    public function launch(OperationRun $operationRun): ProcessResult
    {
        $configRoot = $this->configRoot();
        File::ensureDirectoryExists($configRoot, 0o700);
        $image = $this->images->resolve($operationRun);
        $command = implode(' ', [
            'docker run',
            '--rm',
            '--detach',
            '--name '.$this->escape('orbit-runtime-activation-'.$operationRun->id),
            '--label '.$this->escape("orbit.operation_run_id={$operationRun->id}"),
            '--label '.$this->escape('orbit.role=runtime-activation-runner'),
            '--network '.$this->escape(GatewaySwarmStackRenderer::Network),
            '--mount '
                .$this->escape(
                    "type=bind,source={$configRoot},target=".self::CONTAINER_CONFIG_ROOT,
                ),
            '--env '.$this->escape('ORBIT_CONFIG_ROOT='.self::CONTAINER_CONFIG_ROOT),
            $this->escape($image),
            $this->escape('artisan'),
            $this->escape('orbit:runtime-activation-runner'),
            $this->escape("--operation-run-id={$operationRun->id}"),
        ]);
        $result = Process::timeout(60)->run($command);

        if (! $result->successful()) {
            $message = trim($result->errorOutput().$result->output());

            throw new RuntimeException(
                "Failed to launch runtime activation runner [{$operationRun->id}]: {$message}",
            );
        }

        return $result;
    }

    private function configRoot(): string
    {
        $configRoot = config(
            'orbit.paths.config_root',
            default: '/home/orbit/.config/orbit',
        );

        if (! is_string($configRoot) || trim($configRoot) === '') {
            throw new RuntimeException('Orbit config root is not configured.');
        }

        return rtrim($configRoot, characters: '/');
    }

    private function escape(string $value): string
    {
        return escapeshellarg($value);
    }
}
