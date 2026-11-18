<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Activity\ActivityInputRedaction;
use Closure;
use Illuminate\Support\Arr;

/**
 * A turn's prompt is ordinary private text that no sanitizer recognizes. Activity keeps the rest of `task:turn`'s input; the Operation's
 * input alone holds the prompt (docs/reference/development-containers.md#turn-evidence).
 */
final readonly class TaskTurnActivityRedaction implements ActivityInputRedaction
{
    public function commands(): array
    {
        return ['task:turn'];
    }

    public function input(string $command, Closure $input): array
    {
        return Arr::except($input(), 'prompt');
    }
}
