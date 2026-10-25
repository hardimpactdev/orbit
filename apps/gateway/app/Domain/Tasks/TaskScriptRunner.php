<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;

/** Tracked script profile of the existing detached runner. */
interface TaskScriptRunner
{
    /** @return array<string, mixed> */
    public function inspectScript(Instance $instance, string $script): array;

    /** @param array<string, mixed> $input */
    public function startScript(Instance $instance, array $input, string $runId): TaskCheckProcess;

    public function recoverStart(Instance $instance, string $runId): ?TaskCheckProcess;

    /** @return array<string, mixed> */
    public function readScript(Instance $instance, TaskCheckProcess $process): array;

    public function cancel(Instance $instance, TaskCheckProcess $process): void;
}
