<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use App\Domain\Extensions\ExtensionDependencies;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Models\Node;
use App\Models\TaskVm;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Orbit\Extensions\AiRouter\Data\AiRouterStatusData;
use Orbit\Extensions\AiRouter\Domain\AiRouterHostname;
use Orbit\Extensions\AiRouter\Domain\AiRouterLifecycleLock;
use Orbit\Extensions\AiRouter\Domain\AiRouterPublicationManager;
use Orbit\Extensions\AiRouter\Domain\AiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterRenameCutover;

/**
 * Tears down the collector and deletes the management key. It refuses while Tasks needs CLIProxyAPI or a task VM holds a
 * model key, because only the management key can revoke that key. It holds the model key lock from the check through the
 * teardown, so no task VM gets a key in between.
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
        $lock = Cache::lock(ModelKeyIssuer::Lock, 600);
        try {
            $lock->block(60);
        } catch (LockTimeoutException $exception) {
            throw new ResourceOperationException(
                errorCode: 'ai_router.keys_busy',
                message: 'A task VM model key operation is still running. Run the teardown again.',
                status: 409,
                previous: $exception,
            );
        }

        try {
            return $this->lifecycle->run(fn (): AiRouterStatusData => $this->teardown());
        } finally {
            $lock->release();
        }
    }

    private function teardown(): AiRouterStatusData
    {
        $this->extensions->assertNotRequired('ai-router');
        $held = TaskVm::query()->whereNotNull('model_key')->count();
        if ($held > 0) {
            $vms = $held === 1 ? '1 task VM holds a model key' : "{$held} task VMs hold a model key";
            throw new ResourceOperationException(
                errorCode: 'ai_router.keys_outstanding',
                message: "{$vms} that only the CLIProxyAPI management key can revoke. Tear down after they are destroyed.",
                status: 409,
                details: ['task_vms' => $held],
            );
        }

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
