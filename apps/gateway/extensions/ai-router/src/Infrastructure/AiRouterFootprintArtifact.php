<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Orbit\Extensions\AiRouter\Actions\ConvergeAiRouterAction;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

/** The footprint delegates collector activation to the same owner as setup and the release tick. */
final readonly class AiRouterFootprintArtifact implements NodeFootprintArtifact
{
    public function __construct(
        private AiRouterState $state,
        private ConvergeAiRouterAction $converge,
    ) {}

    public function name(): string
    {
        return 'ai-router';
    }

    public function applies(Node $node): bool
    {
        return $this->state->enabled() && $this->state->nodeId() === $node->id;
    }

    public function digest(Node $node): string
    {
        return hash_file('sha256', base_path('extensions/ai-router/resources/collector.py'))
            ?: throw new ResourceOperationException('ai_router.source_missing', 'The AI router collector script is missing from the Gateway.', 500);
    }

    public function apply(Node $node): bool
    {
        // A pending cutover belongs to the tick. Both entry points acquire the existing lifecycle lock.
        return $this->converge->execute(force: true, deferCutover: true);
    }
}
