<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskCommentType;
use Spatie\LaravelData\Data;

final class StoreTaskGroupCommentData extends Data
{
    public function __construct(
        public TaskCommentType $type,
        public string $body,
        public string $author,
    ) {}
}
