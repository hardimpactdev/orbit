<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use InvalidArgumentException;

final readonly class ProcessRuntimeContextDiff
{
    public function __construct(
        private ProcessRuntimeContextResolver $runtimeContextResolver,
        private ProcessExpectedRuntimeUnits $expectedRuntimeUnits,
        private ProcessOwnershipDetail $ownershipDetail,
    ) {}

    /** @return list<DriftEntry> */
    public function diff(Process $process): array
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof Node) {
            return [];
        }

        $this->runtimeContextResolver->loadRuntimeApp($process, withWorkspaces: true);

        if (! $process->app instanceof App) {
            return [];
        }

        try {
            $runtimeUnits = $this->expectedRuntimeUnits->names($process);
        } catch (InvalidArgumentException $exception) {
            return [
                new DriftEntry(
                    family: 'process',
                    key: 'process.runtime_context_unresolved',
                    kind: DriftKind::Unverifiable,
                    summary: "Process {$process->name} runtime contexts cannot be derived from gateway intent.",
                    detail: [
                        ...$this->ownershipDetail->for($process),
                        'reason' => $exception->getMessage(),
                    ],
                ),
            ];
        }

        if ($runtimeUnits !== []) {
            return [];
        }

        return [
            new DriftEntry(
                family: 'process',
                key: 'process.runtime_context_unresolved',
                kind: DriftKind::Unverifiable,
                summary: "Process {$process->name} has no derived runtime contexts.",
                detail: $this->ownershipDetail->for($process),
            ),
        ];
    }
}
