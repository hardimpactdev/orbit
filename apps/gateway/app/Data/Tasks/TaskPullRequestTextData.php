<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The pull request title and body that a reviewer turn sent from the task copy (docs/reference/tasks.md#the-reviewers-pull-request-text). */
#[MapOutputName(SnakeCaseMapper::class)]
final class TaskPullRequestTextData extends Data
{
    public function __construct(
        public string $title,
        public string $body,
        /** The reviewer turn's `tasks.copy_turn` Operation. */
        public int $operationId,
    ) {}
}
