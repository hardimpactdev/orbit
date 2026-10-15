<?php

declare(strict_types=1);

namespace App\Actions\ProxyCli;

use App\Actions\Extensions\DisableExtensionAction;
use App\Data\ProxyCli\ProxyCliStatusData;
use App\Domain\ProxyCli\ProxyCliHostname;
use App\Domain\ProxyCli\ProxyCliPublicationManager;
use App\Domain\ProxyCli\ProxyCliRuntimeLifecycle;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\TaskVm;

/**
 * Tears down the collector and deletes the management key. It refuses while Tasks needs CLIProxyAPI or a task VM holds a
 * model key, because only the management key can revoke that key.
 */
final readonly class DisableProxyCliAction
{
    public function __construct(
        private ProxyCliState $state,
        private ProxyCliRuntimeLifecycle $runtime,
        private ProxyCliPublicationManager $publication,
        private DisableExtensionAction $extensions,
    ) {}

    public function execute(): ProxyCliStatusData
    {
        $this->extensions->assertNotRequired('proxycli');
        $held = TaskVm::query()->whereNotNull('model_key')->count();
        if ($held > 0) {
            throw new ResourceOperationException(
                errorCode: 'proxycli.keys_outstanding',
                message: "{$held} task VMs hold a model key that only the CLIProxyAPI management key can revoke. Tear down after they are destroyed.",
                status: 409,
                details: ['task_vms' => $held],
            );
        }

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

        return new ProxyCliStatusData(false, $nodeId, $this->state->cacheConnection(), null, ProxyCliHostname::Value);
    }
}
