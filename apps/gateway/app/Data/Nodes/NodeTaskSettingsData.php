<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use Spatie\LaravelData\Data;

/** `worker: none` says the Node hosts no task work, so its Gateway Git runs as the managed user. */
final class NodeTaskSettingsData extends Data
{
    public function __construct(
        public ?string $worker = null,
    ) {}
}
