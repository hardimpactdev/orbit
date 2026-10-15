<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Infrastructure\TaskVms\TaskVmImageBuildChain;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/** Watches the durable row. Interrupting this command never interrupts the queued build. */
final class BuildTaskVmImageCommand extends Command
{
    use ResolvesTaskVmHostNode;

    #[\Override]
    protected $signature = 'task-vms:build-image {node : Id or name of the Incus host Node} {--from-stock : Start from the stock Ubuntu image} {--resume= : Resume a build id, or the newest resumable failed build when no id is given}';

    #[\Override]
    protected $description = 'Queue the task VM base image build and watch its steps.';

    public function handle(TaskVmImageBuildChain $chain, TaskVmSettings $settings): int
    {
        try {
            $node = $this->activeNode((string) $this->argument('node'));
            $settings->host($node->id);
            $resume = $this->option('resume');
            $resuming = $this->input->hasParameterOption('--resume');
            if ($resuming && (($resume !== null && ! ctype_digit((string) $resume)) || $this->option('from-stock'))) {
                $this->error('--resume accepts a build id or no value and cannot be combined with --from-stock.');

                return self::FAILURE;
            }
            $build = ! $resuming
                ? (app(RecipeImageBuilds::class)->reconcile($node->id)[0] ?? throw new ResourceOperationException('task_vm.image_build_running', 'This host is finishing another recipe. The scheduler will queue the current recipe next.', 409))
                : ($resume === null ? $chain->resumeLatest($node) : $chain->resume($node, (int) $resume));
        } catch (ResourceOperationException $error) {
            $this->error("[{$error->errorCode}] {$error->getMessage()}");

            return self::FAILURE;
        }
        $this->info("Build [{$build->id}] queued on builds.");
        $printed = [];
        do {
            $build->refresh();
            foreach ($build->steps as $index => $step) {
                if ($step['seconds'] !== null && ! isset($printed[$index])) {
                    $this->line(sprintf('%-28s %7.1fs', $step['program'] ?? $step['step'], $step['seconds']));
                    $printed[$index] = true;
                }
            }
            if ($build->state === TaskVmImageBuildState::Running) {
                Sleep::for(1)->seconds();
            }
        } while ($build->state === TaskVmImageBuildState::Running);
        if ($build->state === TaskVmImageBuildState::Failed) {
            $entry = array_find($build->steps, fn (array $entry): bool => $entry['step'] === $build->current_step?->value && $entry['program'] === $build->current_program);
            $this->error('Build failed at '.($build->current_program ?? $build->current_step?->value).': '.($entry['error'] ?? 'unknown error'));

            return self::FAILURE;
        }
        $this->info("Build [{$build->id}] succeeded: {$build->image_fingerprint}.");

        return self::SUCCESS;
    }
}
