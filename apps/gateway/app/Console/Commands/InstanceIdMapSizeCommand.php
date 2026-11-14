<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Operations\OperationStatus;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Record a nested-fleet default's converted ID map size, or its old one for a rollback (docs/reference/development-containers.md#convert-a-default).
 * The operator changes the container on the Node first. The record clears the seed, so no copy takes the new size from a seed made
 * for the old one: clones refuse until the default's next passed deployment takes a new seed.
 */
final class InstanceIdMapSizeCommand extends Command
{
    #[\Override]
    protected $signature = 'gateway:instance-idmap-size {instance : The numeric ID of a nested-fleet default} {size : 1165536, or 101000000 for a rollback}';

    #[\Override]
    protected $description = 'Record a nested-fleet default\'s ID map size after its conversion on the Node, and clear its seed.';

    public function handle(): int
    {
        $id = (string) $this->argument('instance');
        $size = (string) $this->argument('size');
        $sizes = [InstanceHostIntent::NestedIdMapSize, InstanceHostIntent::TaskPlaceIds];
        if (! in_array((int) $size, $sizes, true) || (string) (int) $size !== $size) {
            return $this->refuse('instance.idmap_size_invalid', 'The size must be '.implode(' or ', $sizes).'.');
        }
        $instance = ctype_digit($id) ? Instance::query()->find((int) $id) : null;
        $runtime = $instance?->runtime()->first();
        if ($instance === null || $runtime === null || $instance->name !== DefaultInstanceName::Name || $runtime->incus_project !== InstancePlacement::NestedProject) {
            return $this->refuse('instance.idmap_size_not_nested_default', 'Only a nested-fleet default with a container records an ID map size.');
        }
        $open = Operation::query()->where('target_type', 'instance')->where('target_id', $instance->id)
            ->whereNotIn('status', OperationStatus::terminalValues())->value('id');
        if (is_int($open)) {
            return $this->refuse('operation.target_busy', "Operation {$open} holds the default; run this when it has ended.");
        }
        DB::transaction(static fn () => InstanceRuntime::query()->whereKey($runtime->id)->update(['idmap_size' => (int) $size, 'seed_commit' => null]));
        $this->line(json_encode(['instance_id' => $instance->id, 'idmap_size' => (int) $size, 'from' => $runtime->idmap_size, 'seed_commit' => null], JSON_THROW_ON_ERROR));
        $this->info('Recorded. Clones refuse until the next passed deployment of the default takes a new seed.');

        return self::SUCCESS;
    }

    private function refuse(string $code, string $message): int
    {
        $this->error($code.': '.$message);

        return self::FAILURE;
    }
}
