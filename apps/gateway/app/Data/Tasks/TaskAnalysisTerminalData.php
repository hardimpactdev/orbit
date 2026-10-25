<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Data;

/** Safe validated evidence or a refusal, checkpointed before releasing ownership. */
final class TaskAnalysisTerminalData extends Data
{
    /**
     * @param  array{head: string, tree: string}  $after
     * @param  array<string, mixed>|null  $reading
     * @param  array{code: string, message: string, status: int}|null  $error
     * @param  array<string, mixed>|null  $failure
     */
    public function __construct(public int $lease_id, public array $after, public ?array $reading, public ?array $error = null, public ?array $failure = null) {}
}
