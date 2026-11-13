<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\ContainerWorkspaceRead;
use App\Models\Instance;

/**
 * The one boundary for a container's unsaved work (docs/reference/conn.md#remove-an-instance). The remove sheet's read and
 * the destroy's admission use the same live read, so they cannot disagree. Only `clean` admits a removal without consent.
 */
final readonly class ConnInstanceWorkState
{
    public function __construct(private ContainerWorkspaceRead $workspace) {}

    /** @return array<string, mixed> */
    public function read(Instance $instance): array
    {
        return $this->workspace->read($instance);
    }

    public function assertRemovalSafe(Instance $instance): void
    {
        $work = $this->read($instance);
        if ($work['state'] !== 'clean') {
            throw new ResourceOperationException('instance.unsaved_work',
                'The Instance may hold unsaved work. Acknowledge its removal to continue.', 409, details: $work);
        }
    }
}
