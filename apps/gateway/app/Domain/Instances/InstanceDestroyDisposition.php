<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\ProjectDirectInstanceDestroyAction;
use App\Actions\Instances\StartInstanceDestroyAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Lower composition for a parent that owns Route withdrawal and target projection. */
final readonly class InstanceDestroyDisposition
{
    /** Call within the owning parent's admission transaction, before its intent writes.
     * @return array<string,mixed>
     */
    public function prepare(Instance $instance): array
    {
        if (! $instance->placedOnAppDev()) {
            throw new ResourceOperationException('route.target_cleanup_unavailable', 'This parent removal disposition requires development source.', 409);
        }

        return DB::transaction(fn (): array => app(DirectInstanceDestroyIntent::class)->record(
            app(StartInstanceDestroyAction::class)->snapshot($instance, true, routesOwnedByParent: true), parentOwnsRoutes: true,
        ));
    }

    /** Record the parent step's native source capture and return executable lower input.
     * @param  array<string,mixed>  $saved
     * @param  array<string,mixed>  $inspection
     * @return array<string,mixed>
     */
    public function capture(array $saved, array $inspection): array
    {
        return app(InstanceDestroySourceInspection::class)->captureInput($saved, $inspection);
    }

    /** Call after the native source-preparation step succeeds, before detaching its Route targets.
     * @param  array<string,mixed>  $saved
     */
    public function sourcePrepared(array $saved): void
    {
        DB::transaction(function () use ($saved): void {
            $removal = InstanceRemoval::query()->lockForUpdate()->findOrFail(ValidatedData::string($saved['removal_id']));
            if (($removal->native_intent['parent_owns_routes'] ?? false) !== true || $removal->native_sources === null) {
                throw new ResourceOperationException('instance.removal_conflict', 'The parent source checkpoint requires its captured removal intent.', 409);
            }
            if ($removal->status === InstanceRemovalStatus::Completed) {
                return;
            }
            $removal->update(['status' => InstanceRemovalStatus::Removing, 'failed_step' => null, 'error_code' => null]);
            foreach ($removal->members()->lockForUpdate()->get() as $member) {
                if ($member->source_prepared_at === null) {
                    $member->update(['source_prepared_at' => now()]);
                }
            }
        });
    }

    /** The successful bound parent calls this only after native cleanup and its target detach.
     * @param  array<string,mixed>  $saved
     */
    public function project(array $saved): InstanceRemoval
    {
        return app(ProjectDirectInstanceDestroyAction::class)->execute($saved, []);
    }
}
