<?php

declare(strict_types=1);

namespace App\Infrastructure\Projects;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Projects\ProjectUpdateSourceMutator;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Models\Instance;
use App\Models\TaskVm;
use Closure;
use Illuminate\Support\Facades\Config;

/**
 * The checkout steps of a Project update, through the managed account's `project.update-source` agent command
 * (docs/reference/projects.md#update-a-project).
 */
final readonly class RemoteProjectUpdateSourceMutator implements ProjectUpdateSourceMutator
{
    public function __construct(
        private AgentCommandRunner $agents,
        private RepositoryReadAccess $access,
    ) {}

    public function preflightRepository(array $checkouts, string $currentUrl, string $proposedUrl): void
    {
        $read = null;

        foreach ($this->uniqueCheckouts($checkouts) as $checkout) {
            // A task VM gets no token. UpdateProjectAction reads the proposed repository on the Gateway instead.
            $network = ! TaskVm::forNode($checkout->node_id) instanceof TaskVm;
            if ($network) {
                $read ??= $this->access->for($proposedUrl, $checkout->loadMissing('project')->project->source_access);
            }
            $this->run($checkout, [
                'operation' => 'preflight_repository',
                'path' => $checkout->checkout_path,
                'current' => $currentUrl,
                'proposed' => $proposedUrl,
                'git_read' => $network ? $read : null,
            ], 'app-update-repository-preflight', 'project.repository_preflight_failed');
        }
    }

    public function changeOrigins(array $checkouts, string $previousUrl, string $newUrl, array $evidence, ?Closure $record = null): array
    {
        $byKey = [];

        foreach ($evidence as $row) {
            $byKey[self::evidenceKey($row)] = $row;
        }

        foreach ($this->uniqueCheckouts($checkouts) as $checkout) {
            $path = rtrim($checkout->checkout_path, '/');
            $key = 'instance:'.$checkout->id;

            // Evidence from before rows named their Instance carries only the path.
            if (($byKey[$key]['mutated'] ?? $byKey['path:'.$path]['mutated'] ?? false) === true) {
                continue;
            }

            // The intent is recorded before the change, so a failure on a later checkout still restores this one.
            $row = [
                'instance_id' => $checkout->id,
                'path' => $path,
                'previous_url' => $previousUrl,
                'current_url' => $newUrl,
                'mutated' => false,
                'attempted' => true,
            ];
            $byKey[$key] = $row;
            if ($record !== null) {
                $record(array_values($byKey));
            }

            $this->run($checkout, ['operation' => 'set_origin', 'path' => $path, 'url' => $newUrl], 'app-update-repository-origin', 'project.repository_origin_failed');

            $byKey[$key] = [...$row, 'mutated' => true];
            if ($record !== null) {
                $record(array_values($byKey));
            }
        }

        return array_values($byKey);
    }

    /** @param  array{instance_id?: int, path: string}  $row */
    private static function evidenceKey(array $row): string
    {
        return isset($row['instance_id']) ? 'instance:'.$row['instance_id'] : 'path:'.$row['path'];
    }

    public function restoreOrigins(array $mutations): void
    {
        foreach ($mutations as $mutation) {
            if ($mutation['mutated'] !== true && ($mutation['attempted'] ?? false) !== true) {
                continue;
            }

            $checkout = isset($mutation['instance_id'])
                ? Instance::query()->find($mutation['instance_id'])
                : Instance::query()->where('checkout_path', $mutation['path'])->first();

            if (! $checkout instanceof Instance) {
                continue;
            }

            $this->run($checkout, ['operation' => 'restore_origin', 'path' => $mutation['path'], 'url' => $mutation['previous_url']], 'app-update-repository-restore', 'project.repository_origin_failed');
        }
    }

    public function preflightDefaultBranch(Instance $instance, string $newBranch): void
    {
        $project = $instance->loadMissing('project')->project;
        $this->run($instance, [
            'operation' => 'preflight_branch',
            'path' => $instance->checkout_path,
            'branch' => $newBranch,
            'repository' => $project->repository_url,
            'protocols' => array_values(array_filter(Config::array('orbit.git.checkout_protocols', ['https']), is_string(...))),
            'git_read' => $this->access->for($project->repository_url, $project->source_access),
        ], 'app-update-default-branch-preflight', 'project.source_switch_failed');
    }

    public function switchDefaultBranch(Instance $instance, string $newBranch): void
    {
        $this->run($instance, ['operation' => 'switch_branch', 'path' => $instance->checkout_path, 'branch' => $newBranch], 'app-update-default-branch-switch', 'project.source_switch_failed');
    }

    public function restoreDefaultBranch(Instance $instance, string $previousBranch): void
    {
        $this->run($instance, ['operation' => 'restore_branch', 'path' => $instance->checkout_path, 'branch' => $previousBranch], 'app-update-default-branch-restore', 'project.source_switch_failed');
    }

    /**
     * @param  list<Instance>  $checkouts
     * @return list<Instance>
     */
    private function uniqueCheckouts(array $checkouts): array
    {
        $unique = [];

        foreach ($checkouts as $checkout) {
            // Task VMs share one checkout path, so the Node is part of a checkout's identity.
            $unique[$checkout->node_id.':'.rtrim($checkout->checkout_path, '/')] = $checkout;
        }

        return array_values($unique);
    }

    /** @param  array<string, mixed>  $input */
    private function run(Instance $instance, array $input, string $step, string $errorCode): void
    {
        try {
            AgentStep::run($this->agents, $instance->node, 'project.update-source', $input, $step, $errorCode, 'App development');
        } catch (RuntimeConvergenceException $exception) {
            throw new ResourceOperationException(
                errorCode: $exception->errorCode,
                message: $exception->getMessage(),
                status: 409,
                previous: $exception,
            );
        }
    }
}
