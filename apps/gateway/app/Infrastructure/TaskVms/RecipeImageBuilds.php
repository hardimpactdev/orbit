<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Fleet\RecipeMaintenanceAlerts;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Jobs\TaskVms\RefreshUpCloudTaskVmBaseImage;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use App\Models\UpCloudTaskVmImageBuild;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Desired identity is persisted before dispatch; a busy host catches up on the next scheduler check. */
final readonly class RecipeImageBuilds
{
    public function __construct(private RecipeLoader $loader, private TaskVmSettings $settings, private TaskVmImageBuildChain $chain, private RecipeMaintenanceAlerts $alerts, private UpCloudServers $servers) {}

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
                $restart = $existing->restartsAutomatically() || ($retry && $existing->state === TaskVmImageBuildState::Failed);
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
            $recipe->profile('base', $architecture);
            try {
                $builds[] = $this->chain->start($node, $recipe, $generation);
            } catch (TaskVmException $error) {
                // Another scheduler or manual command may have won admission since the read above.
                if ($error->errorCode !== 'task_vm.image_build_running') {
                    throw $error;
                }
            }
        }

        try {
            $upcloud = $this->upcloudStatus($recipe);
            if ($upcloud !== null && $upcloud['template'] === null) {
                $attempt = $upcloud['attempt'];
                if ($attempt === null || $retry) {
                    RefreshUpCloudTaskVmBaseImage::dispatch($recipe->identity, $attempt->id ?? 0);
                } elseif ($attempt->fenced()) {
                    $key = 'task-vms:upcloud-image-build:fenced:'.$attempt->id;
                    if (Cache::add($key, true, 3600)) {
                        $receipt = $this->alerts->halted($attempt->fenceMessage(), $attempt->id, 'machine-recipe-upcloud');
                        if (($receipt['outcome'] ?? null) === 'skipped') {
                            Cache::forget($key);
                        }
                    }
                }
            }
        } catch (Throwable $error) {
            // Optional UpCloud refresh must not block Incus recovery or recipe builds.
            report($error);
        }

        return $builds;
    }

    /** @return array{identity: string, template: string|null, attempt: UpCloudTaskVmImageBuild|null}|null */
    public function upcloudStatus(?MachineRecipe $recipe = null): ?array
    {
        if ($this->settings->upcloud === null) {
            return null;
        }
        $recipe ??= $this->currentRecipe();

        return [
            'identity' => $recipe->identity,
            'template' => $this->servers->currentTemplate($recipe->identity),
            'attempt' => UpCloudTaskVmImageBuild::query()->where('recipe_identity', $recipe->identity)->latest('id')->first(),
        ];
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
