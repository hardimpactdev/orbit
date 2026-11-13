<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\StartInstanceDeployAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The release hook of the nested fleet (docs/cli/instance/container/fleet-refresh.mmd): after a Gateway release, it admits one
 * `instance.deploy` to the release commit for each nested-fleet default whose runtime keeps a fleet. Best effort: a refusal is a line
 * of the result, never an exception, so the release stays live. A default with a deployment open refuses by its Instance lock, so a
 * repeat starts nothing twice.
 */
final readonly class NestedFleetRefresh
{
    public const string Trigger = 'release';

    /** The line of a default whose admission failed with an error that is no refusal. */
    public const string AdmissionFailed = 'operation.admission_failed';

    public function __construct(private StartInstanceDeployAction $deploy) {}

    /** @return array<string, string> `<project>/<instance> on <node>` => `operation:<id>` or the refusal code; empty when no default keeps a fleet */
    public function admitAll(string $commit): array
    {
        $results = [];
        foreach ($this->defaults() as $instance) {
            $label = $instance->project->slug.'/'.$instance->name.' on '.$instance->node->name;
            try {
                $results[$label] = 'operation:'.$this->deploy->pinned($instance, self::Trigger, null, $commit)->id;
            } catch (ResourceOperationException $refused) {
                $results[$label] = $refused->errorCode;
            } catch (Throwable $failed) {
                // One default's failed admission never holds the others, and the release step still ends.
                report($failed);
                $results[$label] = self::AdmissionFailed;
            }
        }
        Log::info('Nested-fleet refresh admissions.', ['commit' => $commit, 'defaults' => $results]);

        return $results;
    }

    /** @return list<Instance> */
    private function defaults(): array
    {
        $runtimes = InstanceRuntime::query()->where('incus_project', InstancePlacement::NestedProject)
            ->whereNull('clone_source_instance_id')->whereNull('t3_port')->orderBy('instance_id')->get();
        $defaults = [];
        foreach ($runtimes as $runtime) {
            $instance = Instance::query()->with(['project', 'node'])->find($runtime->instance_id);
            if ($instance !== null && $instance->name === DefaultInstanceName::Name
                && InstancePlacement::keepsFleet($instance->project, $instance->node, $runtime->incus_project)) {
                $defaults[] = $instance;
            }
        }

        return $defaults;
    }
}
