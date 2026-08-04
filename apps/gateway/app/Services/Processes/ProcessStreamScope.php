<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Workspace;
use InvalidArgumentException;

/**
 * Durable process_events tail scope for browser app SSE.
 *
 * Scoped by resolved app instance, optional workspace (null = instance/main only),
 * and serving node — never a frozen process-id list, so processes added after
 * connect still emit starting/started (and other) events on the same stream.
 */
final readonly class ProcessStreamScope
{
    public function __construct(
        public int $appInstanceId,
        public ?int $workspaceId,
        public int $nodeId,
    ) {}

    public static function fromOwnerContext(ProcessOwnerContext $context): self
    {
        $instance = $context->appInstance;
        $node = $context->node;

        if (! $instance instanceof AppInstance) {
            throw new InvalidArgumentException(
                'Process stream requires a resolved app instance scope.',
            );
        }

        if (! $node instanceof Node) {
            throw new InvalidArgumentException(
                'Process stream requires a resolved serving node scope.',
            );
        }

        $workspace = $context->workspace;

        return new self(
            appInstanceId: $instance->id,
            workspaceId: $workspace instanceof Workspace ? $workspace->id : null,
            nodeId: $node->id,
        );
    }
}
