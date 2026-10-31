<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Instances\ProjectDirectInstanceDestroyAction;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use Illuminate\Support\Facades\DB;

/** Lower composition for a parent that owns Route withdrawal and target projection. */
final readonly class InstanceDestroyDisposition
{
    /** Call within the owning parent's admission transaction, before its intent writes.
     * @return array<string,mixed>
     */
    public function prepare(Instance $instance): array
    {
        return DB::transaction(fn (): array => app(DirectInstanceDestroyIntent::class)->record(
            app(StartInstanceDestroyAction::class)->snapshot($instance, true, routesOwnedByParent: true), parentOwnsRoutes: true,
        ));
    }

    /** The successful bound parent calls this only after native cleanup and its target detach.
     * @param array<string,mixed> $saved
     */
    public function project(array $saved): InstanceRemoval
    {
        return app(ProjectDirectInstanceDestroyAction::class)->execute($saved, []);
    }
}
