<?php

declare(strict_types=1);

namespace App\Actions\ProxyCli;

use App\Data\ProxyCli\EnableProxyCliData;
use App\Data\ProxyCli\ProxyCliStatusData;
use App\Domain\ProxyCli\ProxyCliCollectorEnvironment;
use App\Domain\ProxyCli\ProxyCliHostname;
use App\Domain\ProxyCli\ProxyCliHostnameRoute;
use App\Domain\ProxyCli\ProxyCliPlacement;
use App\Domain\ProxyCli\ProxyCliPublicationManager;
use App\Domain\ProxyCli\ProxyCliRuntimeLifecycle;
use App\Domain\ProxyCli\ProxyCliSnapshotStore;
use App\Domain\ProxyCli\ProxyCliState;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final readonly class EnableProxyCliAction
{
    public function __construct(
        private ProxyCliPlacement $placement,
        private ProxyCliState $state,
        private ProxyCliRuntimeLifecycle $runtime,
        private ProxyCliPublicationManager $publication,
        private ProxyCliSnapshotStore $snapshots,
        private ProxyCliCollectorEnvironment $environment,
        private ProxyCliHostnameRoute $hostnameRoute = new ProxyCliHostnameRoute,
    ) {}

    public function execute(#[SensitiveParameter] EnableProxyCliData $data): ProxyCliStatusData
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

        return new ProxyCliStatusData(
            true,
            $node->id,
            $data->cacheConnection,
            $this->snapshots->snapshot()?->collectedAt,
            ProxyCliHostname::Value,
        );
    }
}
