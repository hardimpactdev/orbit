<?php

declare(strict_types=1);

namespace App\Services\Updates;

interface RunsLocalUpdate
{
    /**
     * Download the prebuilt CLI binary for this host OS/arch and relink the
     * host `orbit` launcher. Verifies the updated binary with `--version`.
     *
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function pullSource(): array;

    /**
     * Install Composer dependencies inside orbit-gateway.
     *
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function installDependencies(): array;

    /**
     * Run Orbit database migrations inside orbit-gateway.
     *
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function runMigrations(): array;
}
