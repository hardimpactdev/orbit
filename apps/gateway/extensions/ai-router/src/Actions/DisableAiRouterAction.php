<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use App\Domain\Extensions\ExtensionDependencies;
use App\Models\Node;
use Orbit\Extensions\AiRouter\Data\AiRouterStatusData;
use Orbit\Extensions\AiRouter\Domain\AiRouterHostname;
use Orbit\Extensions\AiRouter\Domain\AiRouterLifecycleLock;
use Orbit\Extensions\AiRouter\Domain\AiRouterPublicationManager;
use Orbit\Extensions\AiRouter\Domain\AiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterRenameCutover;

/**
 * Tears down the collector and deletes the management key. It refuses while Tasks needs CLIProxyAPI. Task VMs hold only the fixed
 * placeholder key of the guard, so no per-VM key outlives the teardown.
 */
final readonly class DisableAiRouterAction
{
    public function __construct(
        private AiRouterLifecycleLock $lifecycle,
        private AiRouterRenameCutover $cutover,
        private AiRouterState $state,
        private AiRouterRuntimeLifecycle $runtime,
        private AiRouterPublicationManager $publication,
        private ExtensionDependencies $extensions,
    ) {}

    public function execute(): AiRouterStatusData
    {
        return $this->lifecycle->run(fn (): AiRouterStatusData => $this->teardown());
    }

    private function teardown(): AiRouterStatusData
    {
        $this->extensions->assertNotRequired('ai-router');
        $this->cutover->assertComplete();

        $nodeId = $this->state->nodeId();
        $node = $nodeId === null ? null : Node::query()->find($nodeId);

        if ($node instanceof Node) {
            $this->runtime->remove($node);
        }

        // Disable before the publication removal: it republishes private DNS, which keeps the collector name
        // while the extension is still enabled.
        $this->state->disable();

        if ($node instanceof Node) {
            $this->publication->remove($node);
        }

        return new AiRouterStatusData(false, $nodeId, $this->state->cacheConnection(), null, AiRouterHostname::Value);
    }
}
