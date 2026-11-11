<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Instances\InstanceNodeServiceProcessRemovalGuard;
use App\Models\Node;
use App\Models\Process;
use SensitiveParameter;

/** Shared admission for services adopting a named Process or selecting an existing storage Process. */
final readonly class ProcessOwnershipClaim
{
    public function __construct(private InstanceNodeServiceProcessRemovalGuard $attachments) {}

    public function forNode(int $nodeId, string $name): void
    {
        $process = Process::query()->where('owner_type', Node::class)->where('owner_id', $nodeId)->where('name', $name)->first();
        if ($process !== null) {
            $this->assertClaimable($process);
        }
    }

    public function assertClaimable(#[SensitiveParameter] Process ...$processes): void
    {
        foreach ($processes as $process) {
            $this->attachments->assertClaimable($process);
        }
    }
}
