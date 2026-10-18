<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use Illuminate\Validation\ValidationException;
use Orbit\Extensions\AiRouter\Data\AiRouterStatusData;
use Orbit\Extensions\AiRouter\Data\EnableAiRouterData;
use Orbit\Extensions\AiRouter\Domain\AiRouterCollectorEnvironment;
use Orbit\Extensions\AiRouter\Domain\AiRouterHostname;
use Orbit\Extensions\AiRouter\Domain\AiRouterHostnameRoute;
use Orbit\Extensions\AiRouter\Domain\AiRouterPlacement;
use Orbit\Extensions\AiRouter\Domain\AiRouterPublicationManager;
use Orbit\Extensions\AiRouter\Domain\AiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Domain\AiRouterSnapshotStore;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use SensitiveParameter;

final readonly class EnableAiRouterAction
{
    public function __construct(
        private AiRouterPlacement $placement,
        private AiRouterState $state,
        private AiRouterRuntimeLifecycle $runtime,
        private AiRouterPublicationManager $publication,
        private AiRouterSnapshotStore $snapshots,
        private AiRouterCollectorEnvironment $environment,
        private AiRouterHostnameRoute $hostnameRoute = new AiRouterHostnameRoute,
    ) {}

    public function execute(#[SensitiveParameter] EnableAiRouterData $data): AiRouterStatusData
    {
        if (config('task_vms.enabled') === true) {
            $origin = config('task_vms.model_proxy_origin');
            if (! is_string($origin) || rtrim($data->cliproxyUrl, '/') !== rtrim($origin, '/')) {
                throw ValidationException::withMessages([
                    'cliproxy_url' => ['The cliproxy_url must equal task_vms.model_proxy_origin while task VMs are enabled.'],
                ]);
            }
        }

        $node = $this->placement->node($data->nodeId);
        $connection = $this->placement->connection($data->cacheConnection);
        $takeover = $this->hostnameRoute->takeoverCandidate($node);
        $readToken = $this->state->readToken() ?? bin2hex(random_bytes(24));
        $controlToken = $this->state->controlToken() ?? bin2hex(random_bytes(24));
        $this->state->enable(
            $node->id,
            $data->cacheConnection,
            rtrim($data->cliproxyUrl, '/'),
            $data->cliproxyManagementKey,
            $readToken,
            $controlToken,
        );
        // Publish first: a takeover moves the name onto the collector site, which waits for the collector while
        // the runtime converge restarts it. The Route's site would fail those requests instead.
        $this->publication->converge($node, takeover: $takeover);
        $this->runtime->converge($node, $this->environment->build($connection));

        return new AiRouterStatusData(
            true,
            $node->id,
            $data->cacheConnection,
            $this->snapshots->snapshot()?->collectedAt,
            AiRouterHostname::Value,
        );
    }
}
