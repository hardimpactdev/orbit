<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\IncusTaskVmWorkspaceTemplateBuilder;
use App\Infrastructure\TaskVms\TaskVmWorkspaceTemplates;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Builds the workspace template of one `vm` Project on one task VM host. Prints each stage with its time.
 */
final class BuildTaskVmWorkspaceTemplateCommand extends Command
{
    use ResolvesTaskVmHostNode;

    #[\Override]
    protected $signature = 'task-vms:build-workspace-template
        {node : Id or name of the Incus host Node}
        {project : Id or slug of a Project whose groups run on task VMs}';

    #[\Override]
    protected $description = 'Build the workspace template of one task VM Project on one Incus host.';

    public function handle(IncusTaskVmWorkspaceTemplateBuilder $builder): int
    {
        $started = microtime(true);
        try {
            $node = $this->activeNode((string) $this->argument('node'));
            $project = $this->project((string) $this->argument('project'));
            $host = resolve(TaskVmSettings::class)->host($node->id);
            $owner = 'command-'.bin2hex(random_bytes(8));
            // A stopped command deletes its builder VM and unfinished volume, and frees the host for the next build.
            $this->trap([SIGINT, SIGTERM], function (int $signal) use ($builder, $host, $node, $owner): never {
                $builder->abandon($host, $node, $owner);
                $this->error("Stopped by signal [{$signal}]. The builder VM is deleted and the Project keeps its current template.");

                exit(self::FAILURE);
            });
            $volume = $builder->build($host, $node, $project, function (string $stage, float $seconds): void {
                $this->line(sprintf('%-30s %7.1fs', $stage, $seconds));
            }, $owner);
        } catch (ResourceOperationException $exception) {
            $this->error("[{$exception->errorCode}] {$exception->getMessage()}");

            return self::FAILURE;
        }
        Cache::forget(TaskVmWorkspaceTemplates::backoffKey($node->id, $project->id));

        $this->info(sprintf('Node [%s] has the workspace template [%s] of Project [%s] after %.1fs.', $node->name, $volume, $project->slug, microtime(true) - $started));

        return self::SUCCESS;
    }

    private function project(string $reference): Project
    {
        $project = ctype_digit($reference)
            ? Project::query()->find((int) $reference)
            : Project::query()->where('slug', $reference)->first();

        if (! $project instanceof Project) {
            throw new TaskVmException('task_vm.invalid_config', "Project [{$reference}] does not exist.", 422);
        }
        $reason = TaskVmWorkspaceTemplates::unbuildable($project);
        if ($reason !== null) {
            throw new TaskVmException('task_vm.invalid_config', "Project [{$reference}] cannot have a workspace template: {$reason}.", 422);
        }

        return $project;
    }
}
