<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Models\TaskVmImageBuild;
use Illuminate\Console\Command;

/** Reads recent build records without reaching a host or changing a build. */
final class ListTaskVmImageBuildsCommand extends Command
{
    #[\Override]
    protected $signature = 'task-vms:image-builds';

    #[\Override]
    protected $description = 'List the 20 most recent task VM image builds with their steps and errors.';

    public function handle(): int
    {
        $builds = TaskVmImageBuild::query()->with('hostNode')->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get();
        if ($builds->isEmpty()) {
            $this->line('No task VM image builds.');

            return self::SUCCESS;
        }

        foreach ($builds as $build) {
            $step = $build->current_step === TaskVmImageBuildStep::Program ? 'program:'.$build->current_program : ($build->current_step->value ?? '-');
            $this->line(sprintf('Build %d | %s | %s | %s | source: %s (%s)%s',
                $build->id, $build->hostNode->name, $build->state->value, $step,
                $build->source_alias ?? '-', $build->source_fingerprint ?? '-', $build->from_stock ? ' [stock]' : '',
            ));
            if ($build->recipe_identity !== null) {
                $this->line('  Recipe: '.$build->recipe_identity.' | generation: '.$build->recipe_generation);
                foreach ($build->images as $profile => $image) {
                    $this->line("  {$profile} | {$image['project']}/{$image['alias']} | {$image['fingerprint']} | {$image['type']}");
                }
            }
            foreach ($build->steps as $record) {
                $name = $record['step'] === TaskVmImageBuildStep::Program->value ? 'program:'.$record['program'] : $record['step'];
                $seconds = $record['seconds'] === null ? '-' : sprintf('%.1fs', $record['seconds']);
                $this->line(sprintf('  %s | %s | error: %s', $name, $seconds, $record['error'] ?? '-'));
            }
        }

        return self::SUCCESS;
    }
}
