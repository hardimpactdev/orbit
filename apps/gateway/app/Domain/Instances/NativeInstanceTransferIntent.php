<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Projects\GatewaySetupSteps;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectLifecycleStepStore;
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
        if (TaskVm::forNode($instance->node) !== null || $sqliteSource !== null) {
            throw new ResourceOperationException('operation.kind_unavailable', 'This transfer needs native database, VM or runtime composition that is not available yet.', 503);
        }
        if (! is_bool($instance->source_is_laravel)) {
            new InstanceSourceProfileGuard()->refuseMissing();
        }
        // The Gateway renders the destination .env from stored configuration; nothing to render refuses, as env:sync does.
        if (! $instance->environmentValues()->exists()) {
            throw new ResourceOperationException('env.configuration_missing', 'Import or store environment configuration before a transfer.', 409);
        }
    }

    /**
     * The Project setup steps, as in a development create: admission records each step's identity, and the run start
     * reads the commands only while they still match it.
     *
     * @param  list<array<string,mixed>>  $admitted  the recorded identities, compared exactly
     * @return list<array{index:int,name:string,command:string,timeout_seconds:int}>
     */
    public function setupSteps(Instance $instance, array $admitted): array
    {
        $steps = app(ProjectLifecycleStepStore::class)->ordered($instance->project, LifecyclePhase::Setup);
        if (self::identities($steps) !== $admitted) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Project setup steps changed after the transfer was admitted.', 422);
        }

        return array_map(static fn (LifecycleStep $step, int $index): array => ['index' => $index, 'name' => $step->name, 'timeout_seconds' => $step->timeoutSeconds,
            'command' => GatewaySetupSteps::owns($step->command) ? GatewaySetupSteps::script($step->command) : $step->command], $steps, array_keys($steps));
    }

    /**
     * @param  list<LifecycleStep>  $steps
     * @return list<array{name:string,command_digest:string,timeout_seconds:int}>
     */
    private static function identities(array $steps): array
    {
        return array_map(static fn (LifecycleStep $step): array => ['name' => $step->name,
            'command_digest' => hash('sha256', $step->command), 'timeout_seconds' => $step->timeoutSeconds], $steps);
    }

    /** @return array<string,mixed> */
    public function capture(InstanceTransfer $transfer): array
    {
        $instance = Instance::query()->with(['project', 'node'])->findOrFail($transfer->instance_id);
        $destination = $transfer->destinationNode;
        if (TaskVm::forNode($destination) !== null) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Transfer to a task VM requires its native workspace child.', 503);
        }
        $root = data_get($destination->settings, 'apps.path');
        if (! is_string($root) || ! str_starts_with($root, '/')) {
            throw new ResourceOperationException('operation.kind_unavailable', 'A stored destination apps root is required.', 503);
        }

        $sourceInstance = clone $instance;
        $sourceInstance->node_id = $transfer->source_node_id;

        return ['checkout_instances' => app(InstanceDestroyWorktrees::class)->inventory($sourceInstance), 'transfer_id' => $transfer->id, 'instance_id' => $instance->id,
            'source_node_id' => $transfer->source_node_id, 'destination_node_id' => $destination->id,
            'source' => ['source' => $transfer->source_path, 'user' => $instance->node->user,
                'layout' => $transfer->source_layout->value, 'repository_identity' => $instance->project->repository_identity,
                'branch' => $instance->branch, 'sqlite_source' => null, 'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node),
                // The archive leaves this .env out; the Gateway renders the destination's.
                'environment_relative' => ltrim(substr(ApplicationDirectory::resolve($transfer->source_path, $instance->root ?? $instance->project->root).'/.env', strlen($transfer->source_path)), '/')],
            'destination' => ['destination' => $transfer->destination_path, 'root' => $root,
                'application' => ApplicationDirectory::resolve($transfer->destination_path, $instance->root ?? $instance->project->root),
                'environment' => ApplicationDirectory::resolve($transfer->destination_path, $instance->root ?? $instance->project->root).'/.env',
                'user' => $destination->user, 'domain' => $transfer->destination_domain ?? '', 'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($destination)],
            'setup' => self::identities(app(ProjectLifecycleStepStore::class)->ordered($instance->project, LifecyclePhase::Setup)),
            'cutover' => $transfer->cutover_at !== null, 'completed' => $transfer->completed_at !== null];
    }
}
