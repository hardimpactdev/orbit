<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\AppDev\VitePortAllocator;
use App\Domain\Processes\ProcessInstallationBatch;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Owns transfer placement and bindings; Process rendering stays with the Process family. */
final readonly class InstanceTransferProcesses
{
    public function __construct(private ProcessInstallationBatch $batches, private ProcessTargetResolver $targets) {}

    /** @return array<string,mixed> */
    public function capture(InstanceTransfer $transfer): array
    {
        $instance = $transfer->instance;
        $records = Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id)->lockForUpdate()->get();
        if ($records->contains(fn (Process $process): bool => $process->status === LifecycleStatus::Removing || $process->retired_runtime !== null)) {
            $this->refuse();
        }
        $source = $this->targets->forPreparation($instance);
        $placed = clone $instance;
        $placed->forceFill(['node_id' => $transfer->destination_node_id, 'checkout_path' => $transfer->destination_path,
            'name' => $transfer->destination_name])->setRelation('node', $transfer->destinationNode);
        if ($records->contains(fn (Process $process): bool => $process->isVpDev())) {
            foreach (['vite_port' => 'vite_port_assignments', 'ssr_port' => 'ssr_port_assignments'] as $field => $table) {
                $preferred = $instance->{$field};
                if (! is_int($preferred) || $preferred < 1024 || $preferred > 65535) {
                    $this->refuse();
                }
                $placed->{$field} = $this->reservePort($table, $instance->id, $transfer->destination_node_id, $preferred);
            }
        }
        $directory = $placed->source_is_laravel === true ? $placed->applicationDirectory() : $placed->checkout_path;
        $target = new ProcessTarget($placed->node, $placed->node->user, $directory,
            certificateScope: 'app-instance-'.$placed->id, instance: $placed,
            environmentFile: $directory.'/.env', routeDomain: $transfer->destination_domain, onDemandHostStart: $source->onDemandHostStart);

        return ['source_installations' => $this->batches->capture($instance, $source),
            'destination_installations' => $this->batches->capture($instance, $target)];
    }

    /** Validate the frozen source before any placement write, then commit exact relocated paths.
     * @param  array<string,mixed>  $plans
     */
    public function cutover(Instance $instance, InstanceTransfer $transfer, array $plans): void
    {
        $source = ValidatedData::object($plans['source_installations']);
        if ($this->batches->capture($instance, $this->targets->forPreparation($instance)) !== $source) {
            $this->refuse();
        }
        $members = $this->batches->input($source);
        foreach ($members as $member) {
            $member = ValidatedData::object($member);
            $process = Process::query()->lockForUpdate()->findOrFail(ValidatedData::integer($member['id']));
            $placed = $this->batches->placedProcess($process, $transfer->source_path, $transfer->destination_path);
            $process->update(['working_directory' => $placed->working_directory, 'runtime_config' => $placed->runtime_config]);
        }
    }

    /** @return array<string,mixed> */
    public function input(Operation $operation, int $nodeId): array
    {
        $plans = ValidatedData::object($operation->input['process_installations'] ?? []);
        $field = $nodeId === (ValidatedData::object($operation->input['direct_transfer'])['source_node_id'] ?? null)
            ? 'source_installations' : 'destination_installations';
        $batch = $plans[$field] ?? null;
        if (! is_array($batch) || ($batch['node_id'] ?? null) !== $nodeId) {
            return ['node_id' => $nodeId, 'field' => null, 'members' => [], 'ports' => []];
        }
        $ports = [];
        foreach ($this->batches->input($batch) as $member) {
            if (isset(ValidatedData::object($member['input']['control'])['vite_plan'])) {
                foreach (['vite_port', 'ssr_port'] as $port) {
                    $ports[] = ValidatedData::integer(ValidatedData::object(ValidatedData::object($batch['target'])['instance'])[$port]);
                }
                break;
            }
        }

        return ['node_id' => $nodeId, 'field' => $field, 'members' => $this->batches->input($batch), 'ports' => array_values(array_unique($ports))];
    }

    private function reservePort(string $table, int $instanceId, int $nodeId, int $preferred): int
    {
        $recorded = DB::table($table)->where('instance_id', $instanceId)->where('node_id', $nodeId)->value('port');
        if (is_numeric($recorded)) {
            return (int) $recorded;
        }
        $held = [...VitePortAllocator::EXCLUDED_PORTS];
        foreach (['vite_port_assignments', 'ssr_port_assignments'] as $assignments) {
            $held = [...$held, ...DB::table($assignments)->where('node_id', $nodeId)->pluck('port')->all()];
        }
        for ($port = $preferred; $port <= 65535; $port++) {
            if (! in_array($port, $held)) {
                DB::table($table)->insert(['instance_id' => $instanceId, 'node_id' => $nodeId, 'port' => $port]);

                return $port;
            }
        }
        $this->refuse();
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('instance.transfer_runtime_conflict', 'The captured Process placement or port intent is not available.', 409);
    }
}
