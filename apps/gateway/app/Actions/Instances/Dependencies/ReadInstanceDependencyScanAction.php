<?php

declare(strict_types=1);

namespace App\Actions\Instances\Dependencies;

use App\Domain\Instances\Dependencies\DependencyEcosystem;
use App\Domain\Instances\Dependencies\DependencyGraph;
use App\Domain\Instances\Dependencies\DependencyIdentity;
use App\Domain\Instances\Dependencies\DependencyRequirement;
use App\Domain\Instances\Dependencies\DependencyResolution;
use App\Domain\Instances\Dependencies\DependencyScanResult;
use App\Domain\Instances\Dependencies\DependencySnapshot;
use App\Domain\Instances\Dependencies\DependencySource;
use App\Domain\Instances\Dependencies\InstanceDependencyScanResult;
use App\Models\InstanceDependencyObservation;
use App\Models\InstanceDependencyScanAttempt;
use Illuminate\Support\Facades\DB;

final readonly class ReadInstanceDependencyScanAction
{
    public function execute(int $instanceId, DependencyEcosystem $ecosystem, string $subProject = ''): ?DependencyScanResult
    {
        return DB::transaction(function () use ($instanceId, $ecosystem, $subProject): ?DependencyScanResult {
            $observation = InstanceDependencyObservation::query()->where('sub_project', $subProject)
                ->where('instance_id', $instanceId)
                ->where('ecosystem', $ecosystem)
                ->first();
            $attempt = InstanceDependencyScanAttempt::query()->where('sub_project', $subProject)
                ->where('instance_id', $instanceId)
                ->where('ecosystem', $ecosystem)
                ->latest('id')
                ->first();
            $snapshot = $observation === null ? null : $this->snapshot($observation);

            if ($attempt?->error_code !== null) {
                return DependencyScanResult::failed($ecosystem, $attempt->attempted_at, $attempt->error_code, $snapshot, $attempt->error_detail);
            }

            return $snapshot === null ? null : DependencyScanResult::refreshed($snapshot);
        });
    }

    /** @return array<string, InstanceDependencyScanResult>|null */
    public function subProjects(int $instanceId): ?array
    {
        $paths = DB::table('instances')->where('id', $instanceId)->value('dependency_sub_projects');
        if ($paths === null) {
            return null;
        }
        if (! is_string($paths) || ! is_array($paths = json_decode($paths, true, 32, JSON_THROW_ON_ERROR))) {
            throw new \LogicException('Dependency source identities must be a JSON list.');
        }
        $projects = [];
        foreach ($paths as $path) {
            if (! is_string($path)) {
                throw new \LogicException('Dependency source identities must be strings.');
            }
            $projects[$path] = new InstanceDependencyScanResult(
                $instanceId,
                $this->execute($instanceId, DependencyEcosystem::Composer, $path),
                $this->execute($instanceId, DependencyEcosystem::Npm, $path),
            );
        }

        return $projects;
    }

    private function snapshot(InstanceDependencyObservation $observation): DependencySnapshot
    {
        $graph = null;

        if ($observation->present) {
            $resolutions = [];
            $locators = [];
            foreach ($observation->resolutions()->with('package')->orderBy('id')->get() as $resolution) {
                $locators[$resolution->id] = $resolution->locator;
                $resolutions[] = new DependencyResolution(
                    $resolution->locator,
                    new DependencyIdentity($resolution->ecosystem, $resolution->package->name),
                    $resolution->version,
                    $resolution->regular,
                    $resolution->development,
                    $resolution->source_reference,
                    $resolution->integrity,
                );
            }

            $requirements = [];
            foreach ($observation->edges()->orderBy('id')->get() as $edge) {
                $requirements[] = new DependencyRequirement(
                    $edge->from_resolution_id === null ? null : $locators[$edge->from_resolution_id],
                    $edge->to_resolution_id === null ? null : $locators[$edge->to_resolution_id],
                    $edge->name,
                    $edge->constraint,
                    $edge->kind,
                    $edge->scope,
                    $edge->optional,
                );
            }

            $graph = new DependencyGraph($observation->ecosystem, $resolutions, $requirements);
        }

        return new DependencySnapshot(
            $observation->ecosystem,
            new DependencySource(
                $observation->project_root,
                $observation->source_reference,
                $observation->file_hashes,
                $observation->format,
            ),
            $observation->observed_at,
            $graph,
        );
    }
}
