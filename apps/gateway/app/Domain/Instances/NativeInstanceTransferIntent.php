<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\TaskVm;

/** Captures placement only. Native workers inspect, stop and move the actual source. */
final readonly class NativeInstanceTransferIntent
{
    public function assertSupported(Instance $instance, ?string $sqliteSource): void
    {
        if (TaskVm::forNode($instance->node) !== null || $sqliteSource !== null
            || $instance->project->scheduleDefinitions()->exists()) {
            throw new ResourceOperationException('operation.kind_unavailable', 'This transfer needs native database, VM or runtime composition that is not available yet.', 503);
        }
        if (! is_bool($instance->source_is_laravel)) {
            new InstanceSourceProfileGuard()->refuseMissing();
        }
    }

    /** @return array<string,mixed> */
    public function capture(InstanceTransfer $transfer): array
    {
        $instance = Instance::query()->with(['project', 'node'])->findOrFail($transfer->instance_id);
        $destination = $transfer->destinationNode;
        $root = data_get($destination->settings, 'apps.path');
        if (! is_string($root) || ! str_starts_with($root, '/')) {
            throw new ResourceOperationException('operation.kind_unavailable', 'A stored destination apps root is required.', 503);
        }

        return ['transfer_id' => $transfer->id, 'instance_id' => $instance->id,
            'source_node_id' => $transfer->source_node_id, 'destination_node_id' => $destination->id,
            'source' => ['source' => $transfer->source_path, 'user' => $instance->node->user,
                'layout' => $transfer->source_layout->value, 'repository_identity' => $instance->project->repository_identity,
                'branch' => $instance->branch, 'sqlite_source' => null, 'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node)],
            'destination' => ['destination' => $transfer->destination_path, 'root' => $root,
                'application' => ApplicationDirectory::resolve($transfer->destination_path, $instance->root ?? $instance->project->root),
                'environment' => ApplicationDirectory::resolve($transfer->destination_path, $instance->root ?? $instance->project->root).'/.env',
                'user' => $destination->user, 'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($destination)],
            'cutover' => $transfer->cutover_at !== null, 'completed' => $transfer->completed_at !== null];
    }
}
