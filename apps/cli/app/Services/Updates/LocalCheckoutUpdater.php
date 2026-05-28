<?php

declare(strict_types=1);

namespace App\Services\Updates;

use Illuminate\Support\Facades\Process;

class LocalCheckoutUpdater implements RunsLocalUpdate
{
    public function __construct(
        private readonly CheckoutPathResolver $checkoutPathResolver,
    ) {}

    /**
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function pullSource(): array
    {
        $result = Process::path($this->checkoutPathResolver->resolve())
            ->timeout(60)
            ->run('git pull --ff-only');

        return [
            'successful' => $result->successful(),
            'exit_code' => $result->exitCode() ?? 1,
            'output' => trim($result->errorOutput() ?: $result->output()),
        ];
    }

    /**
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function installDependencies(): array
    {
        $result = Process::path($this->checkoutPathResolver->resolve())
            ->timeout(120)
            ->run(['docker', 'exec', 'orbit-runtime', 'composer', '--working-dir=apps/gateway', 'install', '--no-interaction']);

        return [
            'successful' => $result->successful(),
            'exit_code' => $result->exitCode() ?? 1,
            'output' => trim($result->errorOutput() ?: $result->output()),
        ];
    }

    /**
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function runMigrations(): array
    {
        $result = Process::path($this->checkoutPathResolver->resolve())
            ->timeout(60)
            ->run(['docker', 'exec', 'orbit-runtime', 'php', 'apps/gateway/artisan', 'migrate', '--force']);

        return [
            'successful' => $result->successful(),
            'exit_code' => $result->exitCode() ?? 1,
            'output' => trim($result->errorOutput() ?: $result->output()),
        ];
    }
}
