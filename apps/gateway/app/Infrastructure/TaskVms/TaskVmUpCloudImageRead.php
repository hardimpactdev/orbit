<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Operations\OperationChildren;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmUpCloud;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\AgentCommands\GuestAgentCommandRunner;
use App\Models\Operation;
use App\Models\UpCloudTaskVmImageBuild;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class TaskVmUpCloudImageRead
{
    public function __construct(private UpCloudServers $servers, private UpCloudGuest $guest,
        private GuestBootstrap $bootstrap, private GuestAgentCommandRunner $agents, private CommandActivityInputSanitizer $redactor) {}

    public function build(Operation $operation, bool $current = true): UpCloudTaskVmImageBuild
    {
        if ($operation->target_type !== 'task_vm_upcloud_image') {
            throw new ResourceOperationException('operation.execution_forbidden', 'An UpCloud image Operation is required.', 422);
        }
        $build = UpCloudTaskVmImageBuild::query()->findOrFail($operation->target_id);
        if ($build->intent === null || $build->intent['node_id'] !== $operation->node_id || $build->state !== TaskVmImageBuildState::Running) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The UpCloud image attempt does not own this effect.', 422);
        }
        if ($current) {
            $desired = DB::table('task_vm_image_recipe')->where('id', 1)->firstOrFail();
            if ($desired->identity !== $build->recipe_identity || (int) $desired->generation !== $build->intent['generation']) {
                throw new ResourceOperationException('task_vm.image_build_superseded', 'A newer recipe is desired.', 409);
            }
        }

        return $build;
    }

    public function target(UpCloudTaskVmImageBuild $build, string $slot): AgentGuest
    {
        $intent = $build->intent ?? throw new \LogicException('The image intent is missing.');
        $name = $intent['name'].($slot === 'smoke' ? '-smoke' : '');
        $server = $this->servers->find($name, UpCloudServers::ImageLabel, $intent['stamp'], $build->resources[$slot]['uuid'] ?? null);
        $key = $build->bootstrap[$slot]['public'] ?? null;
        if ($server?->address === null || ! is_string($key)) {
            throw new ResourceOperationException('task_vm.image_channel_lost', 'The owned image server cannot be reached.', 409);
        }

        return AgentGuest::ssh($name, 'x86_64', $this->guest->connection($server->address, $key));
    }

    /** @return array<string,mixed> */
    public function read(Operation $operation, ?string $view): array
    {
        $build = $this->build($operation);
        $intent = $build->intent ?? throw new \LogicException('The image intent is missing.');
        if ($view === 'builder-boot' || $view === 'smoke-boot') {
            $slot = $view === 'smoke-boot' ? 'smoke' : 'builder';
            $name = $intent['name'].($slot === 'smoke' ? '-smoke' : '');
            $server = $this->servers->find($name, UpCloudServers::ImageLabel, $intent['stamp'], $build->resources[$slot]['uuid'] ?? null);

            return ['expired' => $this->expired($operation, 'launch-'.$slot, 1200), 'ready' => $server?->state === 'started' && $server->address !== null && $this->bootstrap->ready($this->target($build, $slot), 'UpCloud image guest')];
        }
        if ($view !== null && str_starts_with($view, 'phase-')) {
            $phase = substr($view, 6);
            if (! in_array($phase, ['apt-source', 'packages', 'javascript', 'rust', 'agent', 'pi'], true)) {
                throw new ResourceOperationException('operation.execution_forbidden', 'Unknown recipe phase.', 422);
            }

            $result = $this->agents->runGuest($this->target($build, 'builder'), 'image.inspect-phase', ['key' => ['identity' => $build->recipe_identity, 'profile' => 'base', 'phase' => $phase, 'execution' => 'upcloud-'.$build->id], 'boot_id' => null]);

            return $this->redactor->sanitizeProperties(Arr::only($result, ['state', 'boot_id', 'invocation_id', 'exit_code', 'reason']));
        }
        if ($view === 'builder-power' || $view === 'smoke-power') {
            $slot = $view === 'smoke-power' ? 'smoke' : 'builder';
            $server = $this->servers->find($intent['name'].($slot === 'smoke' ? '-smoke' : ''), UpCloudServers::ImageLabel, $intent['stamp'], $build->resources[$slot]['uuid'] ?? null);

            return ['expired' => $this->expired($operation, 'launch-'.$slot, 300), 'state' => $server?->state, 'address_ready' => $server?->address !== null];
        }
        $verified = array_filter($this->servers->templates(), static fn (string $title): bool => ! str_ends_with($title, TaskVmUpCloud::Unverified));
        arsort($verified, SORT_STRING);

        return ['desired' => true, 'prune_templates' => array_slice(array_keys($verified), 2), 'template_uuid' => $build->template_uuid,
            'template_ready' => $build->template_uuid !== null && $this->servers->templateState($build->template_uuid) === 'online'];
    }

    private function expired(Operation $operation, string $key, int $seconds): bool
    {
        $proof = app(OperationChildren::class)->requireSucceeded($operation, $key);

        return $proof->finished_at?->lte(now()->subSeconds($seconds)) ?? false;
    }
}
