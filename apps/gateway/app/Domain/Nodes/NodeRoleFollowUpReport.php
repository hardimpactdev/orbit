<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

/** Collects optional follow-ups from completed role Operations for retained Gateway callers. */
final class NodeRoleFollowUpReport
{
    /** @var list<string> */
    private array $followUps = [];

    public function record(string $followUp): void
    {
        $this->followUps[] = $followUp;
    }

    /** Reads the recorded follow-ups as one text and clears them, so no later call can reuse them. */
    public function take(): ?string
    {
        $followUps = $this->followUps;
        $this->followUps = [];

        return $followUps === [] ? null : implode(' ', $followUps);
    }
}
