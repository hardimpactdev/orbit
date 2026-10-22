<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Instances\RemovedInstancePathProxyData;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Instances\PathProxy;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Routes\AppRouteCaddyPublisher;
use App\Models\Instance;

final readonly class RemoveInstancePathProxyAction
{
    public function __construct(private DevelopmentProjectionOperationLock $projections, private AppRouteCaddyPublisher $publisher) {}

    public function execute(Instance $instance): RemovedInstancePathProxyData
    {
        return $this->projections->run(function () use ($instance): RemovedInstancePathProxyData {
            $proxy = PathProxy::stored($instance);
            $proxy->update(['enabled' => false]);
            try {
                $this->publisher->publish($proxy->route);
            } catch (NodeCaddyBuildException $exception) {
                throw new ResourceOperationException('path_proxy.publication_failed', 'Withdrawal intent is stored, but Caddy publication failed. Repeat remove or run node:converge --force on its serving Nodes.', 502, previous: $exception);
            }
            $proxy->delete();

            return new RemovedInstancePathProxyData($instance->id);
        });
    }
}
