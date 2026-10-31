<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Actions\Doctor\PlanDoctorCollectionAction;
use App\Actions\Doctor\RunDoctorAction;
use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorNodeReportData;
use App\Data\Doctor\DoctorReportData;
use App\Domain\Problems\ProblemCollector;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;
use UnexpectedValueException;

/** Bounded read snapshots and fingerprint projection. Swamp owns collection order. */
final readonly class DoctorCollectionReads
{
    public const int ProbeSeconds = 12;

    public function __construct(private PlanDoctorCollectionAction $planner, private RunDoctorAction $doctor,
        private ProblemCollector $collector, private CommandDeadline $deadline, private TaskExtensionState $tasks) {}

    /** @return array<string, mixed> */
    public function plan(string $collection): array
    {
        $lock = Cache::lock('problems:collection:'.$collection.':plan-lock', 30);
        $lock->block(2);
        try {
            return ValidatedData::object(Cache::remember('problems:collection:'.$collection.':plan', now()->addDays(7), fn (): array => $this->planner->execute($collection)));
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    public function observe(string $collection, int $nodeId): array
    {
        $plan = $this->storedPlan($collection);
        if (! in_array($nodeId, Arr::array($plan, 'nodes'), true)) {
            throw new ResourceOperationException('observation.target_invalid', 'The Node is outside this collection.', 422);
        }

        return $this->once($collection, 'node-'.$nodeId, function () use ($collection, $nodeId): array {
            $node = Node::query()->find($nodeId);
            if (! $node instanceof Node) {
                return ['status' => 'removed'];
            }
            $startedAt = now();
            try {
                $context = $this->deadline->withinForwardWork(self::ProbeSeconds, fn (): DoctorNodeContext => $this->doctor->context($node));
            } catch (Throwable $error) {
                report($error);
                $context = new DoctorNodeContext($node, new NodeInspectionData(false, null, null, null), inspectionFailed: true);
            }
            Cache::put(self::contextKey($collection, $nodeId), ['inspection' => $context->inspection->toArray(), 'failed' => $context->inspectionFailed, 'observed_at' => $startedAt->toISOString()], now()->addDays(7));

            return ['status' => 'observed'];
        });
    }

    /** @return array<string, mixed> */
    public function inspect(string $collection, int $index): array
    {
        $plan = $this->storedPlan($collection);
        $probe = Arr::array($plan, 'probes')[$index] ?? null;
        if (! is_array($probe)) {
            throw new ResourceOperationException('observation.probe_invalid', 'The resource group is outside this collection.', 422);
        }

        return $this->once($collection, 'probe-'.$index, function () use ($collection, $plan, $probe): array {
            $nodeId = Arr::integer($probe, 'node_id');
            $family = DoctorFamily::from(Arr::string($probe, 'family'));
            $kind = DoctorResourceKind::{Arr::string($probe, 'kind')};
            assert($kind instanceof DoctorResourceKind);
            $resources = new DoctorResources($kind, array_map(static fn (mixed $id): int|string => is_int($id) ? $id : ValidatedData::string($id), array_values(Arr::array($probe, 'ids'))));
            $contexts = [];
            $startedAt = now();
            foreach (Arr::array($plan, 'nodes') as $id) {
                $stored = Cache::get(self::contextKey($collection, $id));
                if ($stored !== null && ! is_array($stored)) {
                    throw new UnexpectedValueException('Invalid cached Doctor observation.');
                }
                if ($id === $nodeId && is_array($stored)) {
                    $startedAt = Carbon::parse(Arr::string($stored, 'observed_at'));
                }
                $node = Node::query()->find($id);
                if ($node instanceof Node) {
                    $contexts[$id] = ! is_array($stored)
                        ? new DoctorNodeContext($node, new NodeInspectionData(false, null, null, null), inspectionFailed: true)
                        : new DoctorNodeContext($node, NodeInspectionData::fromArray(Arr::array($stored, 'inspection')), inspectionFailed: Arr::boolean($stored, 'failed'));
                }
            }
            $context = $contexts[$nodeId] ?? null;
            if (! $context instanceof DoctorNodeContext) {
                return ['status' => 'removed'];
            }
            $context = new DoctorNodeContext($context->node, $context->inspection, $context->inspectionFailed, new DoctorInspectionScope($contexts), $resources);
            try {
                $report = $this->deadline->withinForwardWork(self::ProbeSeconds, fn (): DoctorFamilyReportData => $this->doctor->inspectFamily($context, $family));
            } catch (Throwable $error) {
                report($error);
                $report = DoctorFamilyReportData::fromIssues($family, 0, $resources->failures($family, $nodeId));
            }
            $this->collector->recordDoctor(DoctorReportData::fromNodes([DoctorNodeReportData::fromFamilies($nodeId, $context->node->name, [$report])]), $startedAt);

            return ['status' => 'recorded'];
        });
    }

    /** @return array<string, mixed> */
    public function signals(string $collection): array
    {
        $plan = $this->storedPlan($collection);

        return $this->once($collection, 'signals', function () use ($collection, $plan): array {
            $failures = $this->collector->collect();
            foreach ($failures as $error) {
                report($error);
            }
            if ($failures !== []) {
                throw new ResourceOperationException('observation.signals_failed', 'A signal source could not be collected.', 503);
            }
            foreach (Arr::array($plan, 'nodes') as $id) {
                Cache::forget(self::contextKey($collection, $id));
            }

            return ['status' => 'recorded'];
        });
    }

    /** @return array<string, mixed> */
    private function storedPlan(string $collection): array
    {
        $plan = Cache::get('problems:collection:'.$collection.':plan');
        if (! is_array($plan)) {
            throw new ResourceOperationException('observation.collection_expired', 'The collection read snapshot expired.', 409);
        }

        return ValidatedData::object($plan);
    }

    /**
     * @param  Closure(): array<string, mixed>  $read
     * @return array<string, mixed>
     */
    private function once(string $collection, string $key, Closure $read): array
    {
        if (! $this->tasks->enabled()) {
            return ['status' => 'disabled'];
        }
        $key = 'problems:collection:'.$collection.':'.$key;

        $lock = Cache::lock($key.':lock', 30);
        $lock->block(2);
        try {
            return ValidatedData::object(Cache::remember($key.':receipt', now()->addDays(7), $read));
        } finally {
            $lock->release();
        }
    }

    public static function contextKey(string $collection, int $node): string
    {
        return 'problems:collection:'.$collection.':node:'.$node;
    }
}
