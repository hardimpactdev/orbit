<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Infrastructure\TaskVms\UpCloudServers;
use App\Infrastructure\TaskVms\UpCloudTaskVmImageBuilder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recipe reconciliation requests this refresh when no verified UpCloud template matches the deployed identity.
 * Recheck after queueing: an earlier job or manual build may already have supplied it. The builder keeps its lock
 * and the two newest verified templates. Nothing here enables overflow claims.
 */
final class RefreshUpCloudTaskVmBaseImage implements ShouldBeUnique, ShouldQueue
{
    use TaskVmJob;

    public int $tries = 1;

    /** The `builds` limit, below the queue's `retry_after` of 1800 seconds. A build takes about 20 minutes. */
    public int $timeout = 1700;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(public ?string $recipeIdentity = null, public ?int $afterAttemptId = null, public ?int $generation = null, public ?bool $rebuild = null)
    {
        $this->onTaskVmQueue(GatewayQueue::OptionalBuilds);
    }

    public function uniqueId(): string
    {
        return ($this->recipeIdentity ?? '').':'.($this->generation ?? 0).':'.($this->afterAttemptId ?? 0);
    }

    public function handle(TaskVmSettings $settings, UpCloudServers $servers, UpCloudTaskVmImageBuilder $builder, RecipeImageBuilds $builds): void
    {
        $recipe = $builds->currentRecipe();
        // Jobs queued before the recipe fences or cold rebuilds omit these fields from their serialized payload.
        $identity = $this->recipeIdentity ?? $recipe->identity;
        $afterAttemptId = $this->afterAttemptId ?? 0;
        $generation = $this->generation ?? null;
        $rebuild = $this->rebuild ?? false;
        $desired = DB::table('task_vm_image_recipe')->where('id', 1)->firstOrFail();
        if (($generation !== null && ($desired->identity !== $identity || (int) $desired->generation !== $generation))
            || $settings->upcloud === null || $identity !== $recipe->identity
            || UpCloudTaskVmImageBuilder::lastAttempt($recipe->identity) !== $afterAttemptId
            || (! $rebuild && $servers->currentTemplate($recipe->identity) !== null)) {
            return;
        }

        $template = $builder->build(static function (string $stage, float $seconds): void {
            Log::info('UpCloud task VM template build stage done.', ['stage' => $stage, 'seconds' => $seconds]);
        }, $recipe, $afterAttemptId, $generation);
        Log::info('UpCloud task VM template refreshed.', ['template' => $template]);
    }
}
