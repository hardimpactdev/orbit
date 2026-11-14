<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\NestedFleetInnerVolume;
use App\Domain\Instances\NestedFleetIntent;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Record the inner volume of a nested-fleet default that an operator converted on the Node, or clear it for a rollback
 * (docs/reference/development-containers.md#convert-a-default). The sibling of `gateway:instance-idmap-size`. It reads nothing on the
 * Node: the deployment's start guard reads the volume and the device and requires exactly what the record implies, and the command
 * prints those keys so the operator can check the hand steps before that start. The record clears the seed, so no copy takes a
 * one-snapshot seed for a default whose seed is now a pair: clones refuse until the default's next passed deployment takes the pair.
 */
final class InstanceInnerVolumeCommand extends Command
{
    #[\Override]
    protected $signature = 'gateway:instance-inner-volume {instance : The numeric ID of a nested-fleet default} {volume? : inner-<id>, the name the product reserves for it} {--clear : Clear the record, for a rollback}';

    #[\Override]
    protected $description = 'Record a nested-fleet default\'s inner volume after its conversion on the Node, or clear it, and clear its seed.';

    public function handle(): int
    {
        $id = (string) $this->argument('instance');
        $volume = $this->argument('volume');
        $clear = (bool) $this->option('clear');
        if (($volume === null) === ! $clear) {
            return $this->refuse('instance.inner_volume_arguments_invalid', 'Give the volume name, or --clear, not both.');
        }
        $instance = ctype_digit($id) ? Instance::query()->with('project')->find((int) $id) : null;
        $runtime = $instance?->runtime()->first();
        if ($instance === null || $runtime === null || $runtime->incus_project !== InstancePlacement::NestedProject) {
            return $this->refuse('instance.inner_volume_not_nested', 'Only a container in the nested project records an inner volume.');
        }
        if ($instance->name !== DefaultInstanceName::Name || $runtime->clone_source_instance_id !== null) {
            return $this->refuse('instance.inner_volume_not_default', 'A copy takes its inner volume from its default at its reservation; only a default records one.');
        }
        if (! $instance->project->nested_fleet) {
            return $this->refuse('instance.inner_volume_nested_flag_off', 'The Project is not a nested-fleet Project.');
        }
        // The one name the product reserves for this runtime; any other name would point the default, and every clone, at a volume
        // that is not its own.
        $expected = NestedFleetInnerVolume::name($runtime->container_name);
        if (! $clear && $volume !== $expected) {
            return $this->refuse('instance.inner_volume_name_invalid', "The volume of this default is {$expected}.");
        }
        $name = $clear ? null : $expected;
        // A repeat would clear the seed that the deployment after the first run took, and refuse clones for nothing.
        if ($runtime->inner_volume === $name) {
            return $this->refuse('instance.inner_volume_unchanged', 'The default records '.($name ?? 'no inner volume').' already; nothing changed.');
        }
        // The same row lock as a task copy's admission, so no copy reads the old seed while the record changes.
        $refusal = DB::transaction(static function () use ($instance, $runtime, $name): ?array {
            Instance::query()->whereKey($instance->id)->lockForUpdate()->first();
            $open = Operation::query()->where('target_type', 'instance')->where('target_id', $instance->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->value('id');
            if (is_int($open)) {
                return ['operation.target_busy', "Operation {$open} holds the default; run this when it has ended."];
            }
            try {
                NestedFleetIntent::assertNoCopyInFlight($instance);
            } catch (ResourceOperationException $error) {
                return [$error->errorCode, $error->getMessage()];
            }
            // A copy with its own inner volume needs the default's volume seed half; without the record no seed has one.
            if ($name === null && InstanceRuntime::query()->where('clone_source_instance_id', $instance->id)->whereNotNull('inner_volume')->exists()) {
                return ['instance.inner_volume_copies_with_volume', 'Destroy the copies that have an inner volume first.'];
            }
            InstanceRuntime::query()->whereKey($runtime->id)->update(['inner_volume' => $name, 'seed_commit' => null]);

            return null;
        });
        if ($refusal !== null) {
            return $this->refuse(...$refusal);
        }
        $recorded = $runtime->fresh() ?? $runtime;
        $intent = NestedFleetInnerVolume::intent($recorded);
        $this->line(json_encode(['instance_id' => $instance->id, 'inner_volume' => $name, 'from' => $runtime->inner_volume, 'seed_commit' => null,
            // What the start guard of the next start demands (swamp/ansible/instance_nested.py): exactly these devices beside the
            // others, and the volume with exactly these keys.
            'start_guard' => ['devices' => NestedFleetInnerVolume::allowedDevice($recorded),
                'volume' => $intent === null ? null : ['project' => $recorded->incus_project, 'pool' => $intent['pool'], 'name' => $intent['name'],
                    'config' => [...$intent['config'], 'size' => $intent['size']]],
                'inner_pool' => $intent['inner_pool'] ?? null]], JSON_THROW_ON_ERROR));
        $project = $recorded->incus_project;
        $this->info($intent === null
            ? 'Cleared. The start guard now refuses a disk '.NestedFleetInnerVolume::Device." on {$recorded->container_name}."
            : "Recorded. Check before the start: incus storage volume show {$intent['pool']} {$intent['name']} --project {$project}; incus config device show {$recorded->container_name} --project {$project}.");
        $this->info('Clones refuse with instance.default_missing until the next passed deployment of the default takes a new seed.');

        return self::SUCCESS;
    }

    private function refuse(string $code, string $message): int
    {
        $this->error($code.': '.$message);

        return self::FAILURE;
    }
}
