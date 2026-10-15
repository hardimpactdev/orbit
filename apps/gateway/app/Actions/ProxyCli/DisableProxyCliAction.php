<?php

declare(strict_types=1);

namespace App\Actions\ProxyCli;

use App\Data\ProxyCli\ProxyCliStatusData;
use App\Domain\Extensions\ExtensionDependencies;
use App\Domain\ProxyCli\ProxyCliHostname;
use App\Domain\ProxyCli\ProxyCliPublicationManager;
use App\Domain\ProxyCli\ProxyCliRuntimeLifecycle;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Models\Node;
use App\Models\TaskVm;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Tears down the collector and deletes the management key. It refuses while Tasks needs CLIProxyAPI or a task VM holds a
 * model key, because only the management key can revoke that key. It holds the model key lock from the check through the
 * teardown, so no task VM gets a key in between.
 */
final readonly class DisableProxyCliAction
{
    public function __construct(
        private ProxyCliState $state,
        private ProxyCliRuntimeLifecycle $runtime,
        private ProxyCliPublicationManager $publication,
        private ExtensionDependencies $extensions,
    ) {}

    public function execute(): ProxyCliStatusData
    {
        $lock = Cache::lock(ModelKeyIssuer::Lock, 600);
        try {
            $lock->block(60);
        } catch (LockTimeoutException $exception) {
            throw new ResourceOperationException(
                errorCode: 'proxycli.keys_outstanding',
                message: 'A task VM model key operation is still running. Run the teardown again.',
                status: 409,
                previous: $exception,
            );
        }

        try {
            return $this->teardown();
        } finally {
            $lock->release();
        }
    }

    private function teardown(): ProxyCliStatusData
    {
        $this->extensions->assertNotRequired('proxycli');
        $held = TaskVm::query()->whereNotNull('model_key')->count();
        if ($held > 0) {
            $vms = $held === 1 ? '1 task VM holds a model key' : "{$held} task VMs hold a model key";
            throw new ResourceOperationException(
                errorCode: 'proxycli.keys_outstanding',
                message: "{$vms} that only the CLIProxyAPI management key can revoke. Tear down after they are destroyed.",
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
