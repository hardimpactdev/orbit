<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Enums\Processes\ProcessRuntime;
use App\Models\Process;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeDriverRegistry;

final readonly class EditProcessRuntimeUnitCleaner
{
    public function __construct(
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
    ) {}

    /**
     * @param  list<array{name: string, context: string}>  $previousRuntimeUnits
     * @param  list<array{name: string, context: string}>  $runtimeUnits
     * @return list<array<string, mixed>>
     */
    public function cleanupPrevious(
        ProcessOwnerContext $context,
        ProcessRuntime $previousRuntime,
        array $previousRuntimeUnits,
        array $runtimeUnits,
    ): array {
        $warnings = [];

        foreach ($previousRuntimeUnits as $index => $previousRuntimeUnit) {
            $warning = $this->cleanupPreviousName(
                $context,
                $previousRuntime,
                $previousRuntimeUnit['name'],
                $runtimeUnits[$index]['name'] ?? $previousRuntimeUnit['name'],
            );

            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        return $warnings;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cleanupPreviousName(
        ProcessOwnerContext $context,
        ProcessRuntime $previousRuntime,
        string $previousName,
        string $name,
        ?Process $process = null,
    ): ?array {
        $runtimeChanged = $process instanceof Process && $previousRuntime !== $process->runtime;

        if (! $runtimeChanged && $previousName === $name) {
            return null;
        }

        if ($this->runtimeDrivers->for($previousRuntime)->remove($context->node, $previousName)) {
            return null;
        }

        return [
            'code' => 'process.runtime_unit_cleanup_failed',
            'family' => 'process',
            'message' => "Previous process runtime unit '{$previousName}' could not be removed.",
            'next_command' => 'doctor --family=process --restore',
        ];
    }
}
