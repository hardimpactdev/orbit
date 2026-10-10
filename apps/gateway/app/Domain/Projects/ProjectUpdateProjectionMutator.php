<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Models\Project;
use Closure;

interface ProjectUpdateProjectionMutator
{
    /**
     * @return array<string, mixed>
     */
    public function preflightSlug(Project $project, string $newSlug): array;

    /**
     * @param  array<string, mixed>  $inventory
     * @return array<string, mixed>
     */
    public function prepareSlug(Project $project, string $newSlug, array $inventory): array;

    /**
     * Projects each prepared Route. `$record` stores facts about one prepared row, by its index, in
     * the update evidence before the next step runs, so a rollback knows what the projection did.
     *
     * @param  array<string, mixed>  $prepared
     * @param  (Closure(int, array<string, string>): void)|null  $record
     */
    public function publishSlug(Project $project, string $newSlug, array $prepared, ?Closure $record = null): void;

    /**
     * Whether a prepared Route has cut over, so clients can already see a new domain.
     *
     * @param  array<string, mixed>  $prepared
     */
    public function slugCutOver(array $prepared): bool;

    /**
     * @param  array<string, mixed>  $prepared
     */
    public function rollbackSlug(Project $project, array $prepared): void;

    /**
     * @return array<string, mixed>
     */
    public function preflightRoot(Project $project, string $newRoot): array;

    /**
     * @param  array<string, mixed>  $inventory
     * @return array<string, mixed>
     */
    public function prepareRoot(Project $project, string $newRoot, array $inventory): array;

    /**
     * @param  array<string, mixed>  $prepared
     */
    public function publishRoot(Project $project, string $newRoot, array $prepared): void;

    /**
     * @param  array<string, mixed>  $prepared
     */
    public function rollbackRoot(Project $project, array $prepared): void;
}
