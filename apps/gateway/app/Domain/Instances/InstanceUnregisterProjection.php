<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationStartRefusal;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/**
 * The unregister run's intent: the Route withdrawal, the Processes and the hibernation files of destroy, and nothing of the
 * source, teardown, databases or Git. The terminal projection is destroy's: one record deletion for both kinds.
 */
final readonly class InstanceUnregisterProjection implements DirectOperationProjection, DirectOperationStartRefusal
{
    public function __construct(private DevelopmentInstanceDestroyProjection $destroy) {}

    public function input(Operation $operation): array
    {
        $input = $operation->input;
        $native = ValidatedData::object($input['native_destroy']);
        $processes = array_map(static fn (array $process): array => Arr::only($process, ['process_id', 'runtime', 'name', 'on_demand', 'vite']),
            InstanceLifecycleInput::members(['members' => ValidatedData::object($native['native_runtime'])['processes']]));
        $hibernation = DevelopmentInstanceDestroyProjection::hibernationFiles($input);
        $units = array_map(static fn (array $process): string => '/etc/systemd/system/'.ValidatedData::string($process['name']),
            array_values(array_filter($processes, static fn (array $process): bool => $process['runtime'] === 'systemd')));

        return ['intent' => [
            ...Arr::only($input, ['removal_id', 'node_id', 'instance_id', 'project_id', 'name', 'additional_route_ids', 'route_withdrawn']),
            'members' => array_map(static fn (array $member): array => Arr::only($member, ['instance_id', 'route_id']), InstanceLifecycleInput::members($input)),
            'ssh_execution' => $native['ssh_execution'],
            'route_mutations' => $native['route_mutations'],
            'processes' => $processes,
            'hibernation' => $hibernation,
            'check_paths' => [...$units, ...array_column($hibernation, 'path')],
        ], 'execution_secrets' => ValidatedData::object($native['execution_secrets'])];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return $this->destroy->apply($operation, $data);
    }

    public function refused(Operation $operation): void
    {
        $this->destroy->refused($operation);
    }
}
