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
    protected $signature = 'task-vms:build-recipe {--retry : Restart failed Incus builds and retry missing UpCloud templates, reusing published immutable images}
        {--rebuild : Cold rebuild the current recipe from stock on every configured backend}';

    #[\Override]
    protected $description = 'Queue the current recipe on configured Incus hosts and UpCloud';

    public function handle(RecipeImageBuilds $builds): int
    {
        try {
            $records = $builds->reconcile(retry: (bool) $this->option('retry'), rebuild: (bool) $this->option('rebuild'));
            foreach ($records as $build) {
                $this->line("Build [{$build->id}] | {$build->hostNode->name} | {$build->recipe_identity} | {$build->state->value}");
            }
            $upcloud = $builds->upcloudStatus();
            $fenced = false;
            if ($upcloud !== null) {
                $attempt = $upcloud['attempt'];
                if ($attempt !== null) {
                    $this->line("UpCloud build [{$attempt->id}] | {$attempt->recipe_identity} | {$attempt->status()}");
                }
                if ($this->option('rebuild')) {
                    $this->line('UpCloud cold rebuild requested | '.$upcloud['identity']);
                }
                if ($upcloud['template'] !== null) {
                    $this->line('UpCloud verified template: '.$upcloud['template']);
                } elseif ($attempt === null || $this->option('retry')) {
                    $this->line('UpCloud refresh requested | '.$upcloud['identity']);
                } elseif ($attempt->fenced()) {
                    $this->error($attempt->fenceMessage());
                    $fenced = true;
                } else {
                    $this->line('UpCloud has no verified template for '.$upcloud['identity'].'.');
                }
            }
            if ($records === [] && $upcloud === null) {
                $this->line('No image builds admitted. No hosts are configured, or hosts are finishing another recipe.');
            }

            if (array_any($records, static fn ($build): bool => $build->state === TaskVmImageBuildState::Failed)) {
                $this->error('A recipe build has failed. Resume it with task-vms:build-image, or restart it with --retry.');

                return self::FAILURE;
            }

            return $fenced ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
