<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Data\Processes\ProcessData;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Processes\ProcessRuntimeStatusIndex;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Processes\ProcessUsageIndex;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Collection;

final readonly class ListProcessesAction
{
    public function __construct(
        private NodeAccessAuthorizer $access,
        private ProcessRuntimeStatusIndex $statuses,
        private ProcessUsageIndex $usage,
    ) {}

    /** @return Collection<int, array<string, mixed>> */
    public function execute(ProcessTargetType $targetType, int $targetId): Collection
    {
        $processes = Process::query()
            ->whereIn('owner_type', $targetType->storedTypes())
            ->where('owner_id', $targetId)
            ->orderBy('name')
            ->get();

        return $this->withStatuses($processes);
    }

    /**
     * Processes on Nodes the caller may reach.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function executeAll(Node $consumer): Collection
    {
        return $this->withStatuses(
            Process::query()
                ->when(! $this->access->hasGatewayAuthority($consumer), function ($query) use ($consumer): void {
                    $nodeIds = $this->access->accessibleNodeIds($consumer);
                    $query->where(fn ($owners) => $owners
                        ->where(fn ($nodes) => $nodes
                            ->whereIn('owner_type', ProcessTargetType::Node->storedTypes())
                            ->whereIn('owner_id', $nodeIds))
                        ->orWhere(fn ($instances) => $instances
                            ->whereIn('owner_type', ProcessTargetType::Instance->storedTypes())
                            ->whereIn('owner_id', Instance::query()->select('id')->whereIn('node_id', $nodeIds))));
                })
                ->orderBy('owner_type')
                ->orderBy('owner_id')
                ->orderBy('name')
                ->get(),
        );
    }

    /**
     * @param  Collection<int, Process>  $processes
     * @return Collection<int, array<string, mixed>>
     */
    private function withStatuses(Collection $processes): Collection
    {
        // One lookup for every Process, rather than one round trip each: see
        // ProcessRuntimeStatusIndex for why a list cannot ask each Node in turn.
        $observations = $this->statuses->observations($processes);
        $usage = $this->usage->usage($processes);

        $result = new Collection;

        foreach ($processes as $process) {
            $processUsage = $usage[(int) $process->id] ?? ['cpu' => null, 'memory_bytes' => null];
            $data = ProcessData::fromModel(
                $process,
                $observations[(int) $process->id]['runtime_status'] ?? 'unknown',
                $processUsage['cpu'],
                $processUsage['memory_bytes'],
                observedAt: $observations[(int) $process->id]['observed_at'] ?? null,
                runtimeErrorCode: $observations[(int) $process->id]['runtime_error_code'] ?? null,
            )->toArray();
            $result->push($data);
        }

        return $result;
    }
}
