<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\DB;

/** Desired identity is persisted before dispatch; a busy host catches up on the next scheduler check. */
final readonly class RecipeImageBuilds
{
    public const array Roles = ['gateway', 'app-dev', 'app-prod', 'operator'];

    public function __construct(private RecipeLoader $loader, private TaskVmSettings $settings, private TaskVmImageBuildChain $chain) {}

    /** @return list<TaskVmImageBuild> */
    public function reconcile(?int $hostId = null, bool $retry = false): array
    {
        $recipe = $this->currentRecipe();
        $generation = $this->desire($recipe);
        $builds = [];
        $this->chain->recoverSubmissions();
        foreach ($this->settings->hosts as $host) {
            if ($hostId !== null && $host->nodeId !== $hostId) {
                continue;
            }
            $existing = TaskVmImageBuild::query()->where('host_node_id', $host->nodeId)->where('recipe_identity', $recipe->identity)->first();
            if ($existing !== null) {
                $reactivate = $existing->state === TaskVmImageBuildState::Succeeded && $existing->recipe_generation !== $generation;
                $restart = $existing->state === TaskVmImageBuildState::Failed && ($retry || $existing->current_step === TaskVmImageBuildStep::Launch);
                $builds[] = $reactivate || $restart
                    ? $this->chain->requeueRecipe(Node::query()->findOrFail($host->nodeId), $existing, $generation, $restart)
                    : $existing;

                continue;
            }
            if (TaskVmImageBuild::query()->where('host_node_id', $host->nodeId)->where('state', TaskVmImageBuildState::Running)->exists()) {
                continue;
            }
            $node = Node::query()->findOrFail($host->nodeId);
            $architecture = match ($node->architecture) {
                'x86_64', 'amd64' => 'amd64',
                'aarch64', 'arm64' => 'arm64',
                default => throw new TaskVmException('task_vm.image_build_failed', 'The image host architecture is unsupported.'),
            };
            foreach (['base', ...self::Roles] as $profile) {
                $recipe->profile($profile, $architecture);
            }
            try {
                $builds[] = $this->chain->start($node, $recipe, $generation);
            } catch (TaskVmException $error) {
                // Another scheduler or manual command may have won admission since the read above.
                if ($error->errorCode !== 'task_vm.image_build_running') {
                    throw $error;
                }
            }
        }

        return $builds;
    }

    public function currentRecipe(): MachineRecipe
    {
        $path = rtrim(config()->string('orbit.gateway_checkout'), '/').'/resources/machine-software/recipe.json';

        return $this->loader->load(is_link(dirname(config()->string('orbit.gateway_checkout'), 2)) ? $path : null);
    }

    private function desire(MachineRecipe $recipe): int
    {
        return DB::transaction(function () use ($recipe): int {
            // A write first acquires SQLite's writer fence, which promotion holds across its alias move.
            DB::table('task_vm_image_recipe')->where('id', 1)->where('identity', '!=', $recipe->identity)
                ->orWhere(fn ($query) => $query->where('id', 1)->whereNull('identity'))
                ->update(['identity' => $recipe->identity, 'recipe_json' => $recipe->json, 'generation' => DB::raw('generation + 1')]);
            $state = DB::table('task_vm_image_recipe')->where('id', 1)->firstOrFail();

            return (int) $state->generation;
        });
    }

    public static function alias(string $prefix, string $identity, string $profile): string
    {
        $suffix = '-recipe-'.substr($identity, 7, 12).'-'.$profile;

        return substr($prefix, 0, 64 - strlen($suffix)).$suffix;
    }
}
