<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

/** The Swamp run that confirmed a `workflow.run` request, and whether it had already ended when it was confirmed. */
final readonly class SwampStartReply
{
    public function __construct(public string $runId, public bool $failed) {}
}
