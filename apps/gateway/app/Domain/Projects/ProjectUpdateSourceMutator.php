<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Models\Instance;
use Closure;

interface ProjectUpdateSourceMutator
{
    /**
     * @param  list<Instance>  $checkouts
     */
    public function preflightRepository(array $checkouts, string $currentUrl, string $proposedUrl): void;

    /**
     * @param  list<Instance>  $checkouts
     * @param  list<array{instance_id?: int, path: string, previous_url: string, current_url: string, mutated: bool, attempted?: bool}>  $evidence
     * @param  (Closure(list<array{instance_id?: int, path: string, previous_url: string, current_url: string, mutated: bool, attempted?: bool}>): void)|null  $record  receives the evidence before and after each origin change
     * @return list<array{instance_id?: int, path: string, previous_url: string, current_url: string, mutated: bool, attempted?: bool}>
     */
    public function changeOrigins(array $checkouts, string $previousUrl, string $newUrl, array $evidence, ?Closure $record = null): array;

    /**
     * Restores every row that changed, or that started to change and may have.
     *
     * @param  list<array{instance_id?: int, path: string, previous_url: string, current_url: string, mutated: bool, attempted?: bool}>  $mutations
     */
    public function restoreOrigins(array $mutations): void;

    public function preflightDefaultBranch(Instance $instance, string $newBranch): void;

    public function switchDefaultBranch(Instance $instance, string $newBranch): void;

    public function restoreDefaultBranch(Instance $instance, string $previousBranch): void;
}
