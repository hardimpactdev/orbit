<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Data;

/** The script operation's immutable, persisted dispatch checkpoint. */
final class TaskScriptExecutionData extends Data
{
    /** @param list<string> $args */
    public function __construct(
        public string $execution_id,
        public int $lease_id,
        public int $instance_id,
        public int $node_id,
        public string $repository,
        public string $branch,
        public string $entrypoint_sha256,
        public string $script,
        public array $args,
        public string $head,
        public string $tree,
    ) {}
}
