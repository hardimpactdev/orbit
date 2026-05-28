<?php

declare(strict_types=1);

namespace App\Services\Updates;

interface RunsLocalUpdate
{
    /**
     * Pull the configured Git remote with fast-forward-only semantics.
     *
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function pullSource(): array;

    /**
     * Install Composer dependencies inside orbit-runtime.
     *
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function installDependencies(): array;

    /**
     * Run Orbit database migrations inside orbit-runtime.
     *
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function runMigrations(): array;
}
