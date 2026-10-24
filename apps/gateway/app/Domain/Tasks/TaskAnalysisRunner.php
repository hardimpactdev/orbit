<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;

/** Fixed analysis profile of the existing owned check supervision. */
interface TaskAnalysisRunner
{
    /** Initial origin/main merge base, recorded once by provision; null if unavailable. */
    public function initialAnalysisBase(Instance $instance): ?string;

    /** @return array<string, mixed> */
    public function inspectAnalysis(Instance $instance, string $base): array;

    /** @param array<string, mixed> $input */
    public function startAnalysis(Instance $instance, array $input, string $runId): TaskCheckProcess;

    public function recoverStart(Instance $instance, string $runId): ?TaskCheckProcess;

    /** @return array<string, mixed> */
    public function readAnalysis(Instance $instance, TaskCheckProcess $process): array;

    public function cancel(Instance $instance, TaskCheckProcess $process): void;
}
