<?php

declare(strict_types=1);

namespace App\Services\Processes;

/**
 * Wake-only concurrent dispatch for runtime process starts.
 *
 * Callables must stay free of durable parent-side DB writes; the parent records
 * process events before and after this runner returns.
 */
interface RuntimeWakeConcurrentRunner
{
    /**
     * @param  array<array-key, callable(): bool>  $tasks
     * @return array<array-key, bool>
     */
    public function run(array $tasks): array;
}
