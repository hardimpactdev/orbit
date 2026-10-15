<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use Illuminate\Console\Command;
use Throwable;

final class BuildTaskVmRecipeCommand extends Command
{
    #[\Override]
    protected $signature = 'task-vms:build-recipe {--retry : Restart failed builds, reusing any already published immutable images}';

    #[\Override]
    protected $description = 'Queue the current recipe image set on every configured task VM host';

    public function handle(RecipeImageBuilds $builds): int
    {
        try {
            $records = $builds->reconcile(retry: (bool) $this->option('retry'));
            foreach ($records as $build) {
                $this->line("Build [{$build->id}] | {$build->hostNode->name} | {$build->recipe_identity} | {$build->state->value}");
            }
            if ($records === []) {
                $this->line('No image builds admitted. No hosts are configured, or hosts are finishing another recipe.');
            }

            if (array_any($records, static fn ($build): bool => $build->state === TaskVmImageBuildState::Failed)) {
                $this->error('A recipe build has failed. Resume it with task-vms:build-image, or restart it with --retry.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
