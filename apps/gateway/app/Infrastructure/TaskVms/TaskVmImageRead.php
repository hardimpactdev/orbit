<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Operations\OperationChildren;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\AgentCommands\GuestAgentCommandRunner;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Read-only views for native Swamp image control. An unreadable state never means an absent effect. */
final readonly class TaskVmImageRead
{
    public function __construct(private TaskVmSettings $settings, private TaskVmVolumes $volumes, private GuestBootstrap $bootstrap,
        private GuestAgentCommandRunner $agents, private RecipeImageBuildSteps $eligibility, private CommandActivityInputSanitizer $redactor) {}

    public function build(Operation $operation): TaskVmImageBuild
    {
        if ($operation->target_type !== 'task_vm_image') {
            throw new ResourceOperationException('operation.execution_forbidden', 'An image build Operation is required.', 422);
        }
        $build = TaskVmImageBuild::query()->findOrFail($operation->target_id);
        if ($operation->node_id !== $build->host_node_id || ($operation->input['attempt'] ?? null) !== $build->attempt || $build->cleanup_started_at !== null) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This Operation no longer owns the image attempt.', 422);
        }
        $this->eligibility->assertCurrent($build);

        return $build;
    }

    /** @return array<string, mixed> */
    public function state(Operation $operation): array
    {
        $build = $this->build($operation);
        $host = $this->settings->host($build->host_node_id);
        $fingerprint = $build->images['base']['fingerprint'] ?? null;

        return ['desired' => true, 'fingerprint' => $fingerprint,
            'seed_ready' => $fingerprint !== null && $this->volumes->exists($host, $build->hostNode, TaskVmVolumes::seed($fingerprint)),
            'seed_pending' => $fingerprint !== null && $this->volumes->exists($host, $build->hostNode, TaskVmVolumes::newSeed($fingerprint))];
    }

    /** @return array<string, mixed> */
    public function boot(Operation $operation, bool $smoke, bool $seed = false): array
    {
        $build = $this->build($operation);
        $host = $this->settings->host($build->host_node_id);
        $name = $build->builder_vm_name.($smoke ? '-smoke' : '');
        $launch = Operation::query()->where('target_type', 'task_vm_image')->where('target_id', $build->id)->where('kind', 'task_vm.image_launch')->where('input->vm->name', $name)->where('status', 'succeeded')->latest('id')->first();
        $expired = false;
        if ($launch !== null) {
            $parent = Operation::query()->findOrFail(ValidatedData::integer($launch->getAttribute('parent_operation_id')));
            $proof = app(OperationChildren::class)->requireSucceeded($parent, ValidatedData::string($launch->getAttribute('child_key')));
            $expired = $proof->finished_at?->lte(now()->subSeconds(600)) ?? false;
        }

        return ['ready' => $this->bootstrap->ready(AgentGuest::incus($build->hostNode, $host->project, $build->builder_vm_name.($smoke ? '-smoke' : '')), 'recipe image guest'), 'expired' => $expired];
    }

    /** @return array<string, mixed> */
    public function phase(Operation $operation, string $phase, ?string $bootId = null): array
    {
        $build = $this->build($operation);
        if (! in_array($phase, ['apt-source', 'packages', 'javascript', 'rust', 'agent', 'pi'], true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The image phase is not declared.', 422);
        }
        $host = $this->settings->host($build->host_node_id);
        $step = array_find($build->steps, static fn (array $entry): bool => $entry['program'] === 'base:'.$phase);
        $state = $step['phase_state'] ?? [];
        $result = $this->agents->runGuest(AgentGuest::incus($build->hostNode, $host->project, (string) $build->builder_vm_name), 'image.inspect-phase', [
            'key' => ['identity' => $build->recipe_identity, 'profile' => 'base', 'phase' => $phase, 'execution' => (string) $build->id],
            'boot_id' => $bootId ?? $state['boot_id'] ?? null,
        ]);
        $safe = Arr::only($result, ['boot_id', 'state', 'invocation_id', 'exit_code', 'reason']);
        $safe['attempt'] = $state['attempt'] ?? $build->attempt;

        return $this->redactor->sanitizeProperties($safe);
    }
}
