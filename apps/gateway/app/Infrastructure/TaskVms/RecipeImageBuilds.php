<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Actions\TaskVms\StartTaskVmImageOperationAction;
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
    public function reconcile(?int $hostId = null, bool $retry = false, bool $rebuild = false): array
    {
        $recipe = $this->currentRecipe();
        if ($rebuild && ($retry || $hostId !== null)) {
            throw new TaskVmException('task_vm.image_build_failed', 'A cold rebuild targets every configured backend and cannot be combined with --retry.');
        }
        $builds = $rebuild ? $this->coldRebuild($recipe) : [];
        $generation = $rebuild ? (int) DB::table('task_vm_image_recipe')->where('id', 1)->firstOrFail()->generation : $this->desire($recipe);
        foreach ($this->settings->hosts as $host) {
            if ($rebuild || ($hostId !== null && $host->nodeId !== $hostId)) {
                continue;
            }
            $existing = TaskVmImageBuild::query()->where('host_node_id', $host->nodeId)->where('recipe_identity', $recipe->identity)->latest('id')->first();
            if ($existing !== null && $existing->recipe_generation !== $generation) {
                $retained = TaskVmImageBuild::query()->where('host_node_id', $host->nodeId)->where('recipe_identity', $recipe->identity)
                    ->where('state', TaskVmImageBuildState::Succeeded)->whereNotNull('promoted_at')->latest('id')->first();
                if ($retained !== null) {
                    $existing = $retained;
                }
            }
            if ($existing !== null) {
                if ($existing->state === TaskVmImageBuildState::Running) {
                    app(StartTaskVmImageOperationAction::class)->execute($existing, $existing->current_step !== null);
                }
                $reactivate = $existing->state === TaskVmImageBuildState::Succeeded && $existing->recipe_generation !== $generation;
                if ($reactivate && TaskVmImageEvidence::published($existing) === null) {
                    $builds[] = $this->chain->start(Node::query()->findOrFail($host->nodeId), $recipe, $generation);

                    continue;
                }
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
            try {
                $builds[] = $this->start($node, $recipe, $generation);
            } catch (TaskVmException $error) {
                // Another scheduler or manual command may have won admission since the read above.
                if ($error->errorCode !== 'task_vm.image_build_running') {
                    throw $error;
                }
            }
        }

        try {
            $upcloud = $this->upcloudStatus($recipe);
            $request = DB::table('task_vm_image_recipe')->where('id', 1)->value('upcloud_rebuild_after_attempt');
            $coldPending = is_numeric($request) && UpCloudTaskVmImageBuilder::lastAttempt($recipe->identity) === (int) $request;
            if ($upcloud !== null && ($upcloud['template'] === null || $coldPending)) {
                $attempt = $upcloud['attempt'];
                if ($attempt === null || $retry || $coldPending) {
                    RefreshUpCloudTaskVmBaseImage::dispatch($recipe->identity, $attempt->id ?? 0, $generation, $coldPending);
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
                ->update(['identity' => $recipe->identity, 'recipe_json' => $recipe->json, 'generation' => DB::raw('generation + 1'), 'upcloud_rebuild_after_attempt' => null]);
            $state = DB::table('task_vm_image_recipe')->where('id', 1)->firstOrFail();

            return (int) $state->generation;
        });
    }

    /**
     * A cold rebuild stays inside the desired generation; no Node state or promoted image is changed.
     *
     * @return list<TaskVmImageBuild>
     */
    private function coldRebuild(MachineRecipe $recipe): array
    {
        return DB::transaction(function () use ($recipe): array {
            // The same writer fence guards starts, allocation, desired changes, and promotion.
            DB::table('task_vm_image_recipe')->where('id', 1)->update(['generation' => DB::raw('generation')]);
            $state = DB::table('task_vm_image_recipe')->where('id', 1)->firstOrFail();
            if ($state->identity !== $recipe->identity) {
                throw new TaskVmException('task_vm.image_build_failed', 'Cold rebuild requires the current desired recipe. Reconcile it before rebuilding images.');
            }
            $generation = (int) $state->generation;
            $lastAttempt = UpCloudTaskVmImageBuilder::lastAttempt($recipe->identity);
            $pending = $state->identity === $recipe->identity && $state->upcloud_rebuild_after_attempt !== null
                && (int) $state->upcloud_rebuild_after_attempt === $lastAttempt;
            if (TaskVmImageBuild::query()->where('state', TaskVmImageBuildState::Running)
                ->where(fn ($query) => $query->where('recipe_identity', $recipe->identity)
                    ->orWhereIn('host_node_id', array_map(static fn ($host): int => $host->nodeId, $this->settings->hosts)))->exists()
                || UpCloudTaskVmImageBuild::query()->where('recipe_identity', $recipe->identity)
                    ->where('state', TaskVmImageBuildState::Running)->where('started_at', '>', now()->subSeconds(UpCloudTaskVmImageBuild::RunningSeconds))->exists()
                || $pending) {
                throw new TaskVmException('task_vm.image_build_running', 'A recipe image build is running or its cold rebuild is pending. Wait for it to finish before rebuilding.');
            }
            DB::table('task_vm_image_recipe')->where('id', 1)->update([
                'upcloud_rebuild_after_attempt' => $this->settings->upcloud === null ? null : $lastAttempt,
            ]);
            $builds = [];
            foreach ($this->settings->hosts as $host) {
                $node = Node::query()->findOrFail($host->nodeId);
                $builds[] = $this->start($node, $recipe, $generation);
            }

            return $builds;
        });
    }

    private function start(Node $node, MachineRecipe $recipe, int $generation): TaskVmImageBuild
    {
        $architecture = MachineRecipe::architecture($node->architecture)
            ?? throw new TaskVmException('task_vm.image_build_failed', 'The image host architecture is unsupported.');
        $recipe->profile('base', $architecture);

        return $this->chain->start($node, $recipe, $generation);
    }

    public static function alias(string $prefix, string $identity, string $profile, int $buildId): string
    {
        $suffix = '-recipe-'.substr($identity, 7, 12).'-'.$buildId.'-'.$profile;

        return substr($prefix, 0, 64 - strlen($suffix)).$suffix;
    }
}
