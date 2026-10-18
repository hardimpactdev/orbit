<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Actions\Processes\RestartProcessAction;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Fleet\NodeShell;
use App\Models\Node;
use App\Models\Process;
use Orbit\Extensions\AiRouter\Domain\AiRouterProcess;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

/**
 * The AiRouter collector script on the collector's Node. A changed script restarts the collector,
 * a Node-owned Process; it is not an Instance Process. The ai-router provider contributes it to the Node
 * footprint ([Extensions](/reference/extensions#plug-in-points)).
 */
final readonly class AiRouterFootprintArtifact implements NodeFootprintArtifact
{
    public function __construct(
        private AiRouterState $state,
        private AiRouterSourcePublisher $publisher,
        private NodeShell $shell,
        private RestartProcessAction $restart,
        private ?string $scriptPath = null,
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
        return hash('sha256', $this->script());
    }

    public function apply(Node $node): bool
    {
        $result = $this->shell->run($node, $this->publisher->command($this->script()));

        if (! $result->succeeded()) {
            throw new ResourceOperationException('ai_router.source_publication_failed', "Could not install the ai-router collector on node [{$node->name}].", 502);
        }

        if (! str_contains($result->stdout, AiRouterSourcePublisher::Published)) {
            return false;
        }

        $collector = Process::query()
            ->where('owner_type', Node::class)
            ->where('owner_id', $node->id)
            ->where('name', AiRouterProcess::NAME)
            ->first();

        if ($collector instanceof Process) {
            $this->restart->execute($collector);
        }

        return true;
    }

    private function script(): string
    {
        $script = @file_get_contents($this->scriptPath ?? base_path('extensions/ai-router/resources/collector.py'));

        if (! is_string($script) || $script === '') {
            throw new ResourceOperationException('ai_router.source_missing', 'The ai-router collector script is missing from the Gateway.', 500);
        }

        return $script;
    }
}
