<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Data;

final class StartTaskPublishData extends Data
{
    /** @param array{title: string, body: string}|null $pull_request */
    public function __construct(public int $check, public ?array $pull_request = null) {}
}
