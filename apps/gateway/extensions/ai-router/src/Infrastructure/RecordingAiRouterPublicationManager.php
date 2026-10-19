<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Models\Node;
use App\Models\Route;
use Orbit\Extensions\AiRouter\Domain\AiRouterPublicationManager;

final class RecordingAiRouterPublicationManager implements AiRouterPublicationManager
{
    public bool $converged = false;

    public bool $removed = false;

    public ?int $nodeId = null;

    public ?int $takeoverRouteId = null;

    public function converge(Node $node, ?Route $takeover = null): void
    {
        $this->converged = true;
        $this->nodeId = $node->id;
        $this->takeoverRouteId = $takeover?->id;
    }

    public function remove(Node $node): void
    {
        $this->removed = true;
        $this->nodeId = $node->id;
    }
}
