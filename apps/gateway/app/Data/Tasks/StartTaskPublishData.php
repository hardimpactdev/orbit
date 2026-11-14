<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Data;

final class StartTaskPublishData extends Data
{
    /**
     * Without a check, a task copy's group publishes its done check with Orbit's title and body (docs/reference/tasks.md#release-a-waiting-group).
     *
     * @param  array{title: string, body: string, draft?: bool}|null  $pull_request
     */
    public function __construct(public ?int $check = null, public ?array $pull_request = null) {}
}
